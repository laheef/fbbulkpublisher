#!/usr/bin/env node
'use strict';

/**
 * Assemble the self-contained runtime that ships inside the Windows application.
 *
 *   runtime/
 *   ├── node/            the Node runtime used to execute the worker
 *   ├── playwright/      pinned Playwright package (browsers disabled)
 *   ├── chromium/        the exact Chromium revision Playwright expects
 *   ├── ffmpeg/          ffmpeg.exe + ffprobe.exe
 *   └── manifest.json    SHA-256 of every component, checked at startup
 *
 * Nothing here is required on the operator's machine: it is all bundled, so the
 * end user never installs Node.js, npm, Playwright, Chromium, FFmpeg, Python,
 * Docker or browser drivers (prompt §4, §13, §74).
 *
 * Usage:
 *   node build-runtime.js                 # build runtime/ and the manifest
 *   node build-runtime.js --payload       # also write runtime-payload/*.zip for repair installs
 *   node build-runtime.js --only=chromium # rebuild a single component
 */

const fs = require('fs');
const path = require('path');
const os = require('os');
const crypto = require('crypto');
const https = require('https');
const { execFileSync, spawnSync } = require('child_process');

const ROOT = path.resolve(__dirname, '..');
const RUNTIME = path.join(ROOT, 'windows-app', 'runtime');
const PAYLOAD = path.join(ROOT, 'windows-app', 'runtime-payload');
const CACHE = path.join(ROOT, 'installer', '.cache');

const args = process.argv.slice(2);
const ONLY = (args.find((a) => a.startsWith('--only=')) || '').split('=')[1] || null;
const WANT_PAYLOAD = args.includes('--payload');

/**
 * Pinned sources. Update these deliberately, in a release commit, with the hash.
 * A null hash means "pin it on this build and record what we got".
 */
const SOURCES = {
    node: {
        label: 'Node runtime',
        version: process.env.LINKEASY_NODE_VERSION || '20.18.0',
        url: (v) => `https://nodejs.org/dist/v${v}/node-v${v}-win-x64.zip`,
        sha256: null,
        extractTo: 'node',
        // The archive contains a single top-level folder; flatten it.
        flatten: true,
        keep: ['node.exe', 'LICENSE'],
    },
    playwright: {
        label: 'Playwright',
        version: require(path.join(ROOT, 'windows-app', 'package.json')).dependencies.playwright,
        url: (v) => `https://registry.npmjs.org/playwright/-/playwright-${v}.tgz`,
        sha256: null,
        extractTo: 'playwright',
        npmInstall: true,
    },
    chromium: {
        label: 'Chromium',
        version: process.env.LINKEASY_CHROMIUM_REVISION || '1148',
        // Playwright publishes prebuilt browsers; the revision must match the
        // Playwright version above.
        url: (v) => `https://playwright.azureedge.net/builds/chromium/${v}/chromium-win64.zip`,
        sha256: null,
        extractTo: 'chromium',
    },
    ffmpeg: {
        label: 'FFmpeg',
        version: process.env.LINKEASY_FFMPEG_VERSION || '7.1',
        url: (v) => `https://www.gyan.dev/ffmpeg/builds/ffmpeg-release-essentials.zip`,
        sha256: null,
        extractTo: 'ffmpeg',
        // The archive nests the binaries; pull out just what we ship.
        flatten: true,
        keep: ['ffmpeg.exe', 'ffprobe.exe', 'LICENSE'],
    },
};

function log(message) {
    process.stdout.write(`[runtime] ${message}\n`);
}

function sha256File(file) {
    return new Promise((resolve, reject) => {
        const hash = crypto.createHash('sha256');
        const stream = fs.createReadStream(file);
        stream.on('error', reject);
        stream.on('data', (chunk) => hash.update(chunk));
        stream.on('end', () => resolve(hash.digest('hex')));
    });
}

async function sha256Tree(dir) {
    // Deterministic digest of a directory: sorted relative paths + file hashes.
    const entries = [];
    const walk = (current, prefix) => {
        for (const name of fs.readdirSync(current).sort()) {
            const full = path.join(current, name);
            const stat = fs.statSync(full);
            if (stat.isDirectory()) {
                walk(full, path.join(prefix, name));
            } else {
                entries.push({ rel: path.join(prefix, name), size: stat.size, file: full });
            }
        }
    };
    walk(dir, '');

    const hash = crypto.createHash('sha256');
    for (const entry of entries) {
        hash.update(entry.rel.replace(/\\/g, '/'));
        hash.update(':');
        hash.update(String(entry.size));
        hash.update(':');
        hash.update(await sha256File(entry.file));
        hash.update('\n');
    }
    return { digest: hash.digest('hex'), files: entries.length, bytes: entries.reduce((n, e) => n + e.size, 0) };
}

function download(url, target) {
    fs.mkdirSync(path.dirname(target), { recursive: true });
    const file = fs.createWriteStream(target);

    return new Promise((resolve, reject) => {
        const request = https.get(url, { headers: { 'User-Agent': 'linkeasy-installer/1.0' } }, (response) => {
            if (response.statusCode >= 300 && response.statusCode < 400 && response.headers.location) {
                file.close();
                fs.rmSync(target, { force: true });
                download(response.headers.location, target).then(resolve, reject);
                return;
            }
            if (response.statusCode !== 200) {
                file.close();
                fs.rmSync(target, { force: true });
                reject(new Error(`HTTP ${response.statusCode} for ${url}`));
                return;
            }

            let received = 0;
            const total = Number.parseInt(response.headers['content-length'] || '0', 10);
            response.on('data', (chunk) => {
                received += chunk.length;
                if (total > 0 && process.stdout.isTTY) {
                    process.stdout.write(`\r[runtime] downloading ${path.basename(target)} — ${Math.round((received / total) * 100)}%`);
                }
            });
            response.pipe(file);
            file.on('finish', () => file.close(resolve));
        });

        request.on('error', (error) => {
            file.close();
            reject(error);
        });
        request.setTimeout(300000, () => request.destroy(new Error('Download timed out')));
    });
}

async function ensureArchive(component, spec) {
    const version = spec.version;
    const archive = path.join(CACHE, `${component}-${version}${spec.url(version).endsWith('.tgz') ? '.tgz' : '.zip'}`);

    if (fs.existsSync(archive) && spec.sha256) {
        const digest = await sha256File(archive);
        if (digest === spec.sha256) {
            log(`${spec.label} archive is cached and matches its pinned hash.`);
            return archive;
        }
        log(`${spec.label} cached archive does not match its pinned hash; downloading again.`);
        fs.rmSync(archive, { force: true });
    }

    if (!fs.existsSync(archive)) {
        log(`Downloading ${spec.label} ${version}…`);
        await download(spec.url(version), archive);
    }

    const digest = await sha256File(archive);
    if (spec.sha256 && digest !== spec.sha256) {
        throw new Error(`${spec.label} failed hash verification.\n  expected ${spec.sha256}\n  actual   ${digest}`);
    }
    if (!spec.sha256) {
        log(`${spec.label} hash (pin this in build-runtime.js): ${digest}`);
    }

    return archive;
}

async function buildComponent(component) {
    const spec = SOURCES[component];
    if (!spec) {
        throw new Error(`Unknown component: ${component}`);
    }

    const target = path.join(RUNTIME, spec.extractTo);
    fs.rmSync(target, { recursive: true, force: true });
    fs.mkdirSync(target, { recursive: true });

    // Playwright is installed with npm so its dependency tree comes along.
    if (spec.npmInstall) {
        log(`Installing ${spec.label} ${spec.version} into the runtime…`);
        // Node's child_process hardened its handling of Windows .cmd/.bat
        // shims (CVE-2024-27980, fixed in all current Node LTS releases):
        // spawning npm.cmd directly, without shell: true, now fails before
        // the process even starts — no npm output, just a generic error.
        // shell: true on Windows restores the old, working behaviour.
        const result = spawnSync(
            process.platform === 'win32' ? 'npm.cmd' : 'npm',
            ['install', '--prefix', target, '--no-save', '--omit=dev',
                `playwright@${spec.version}`, `playwright-core@${spec.version}`],
            {
                stdio: 'inherit',
                shell: process.platform === 'win32',
                env: { ...process.env, PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD: '1' },
            },
        );
        if (result.error) {
            throw new Error(`npm install failed for ${spec.label}: ${result.error.message}`);
        }
        if (result.status !== 0) {
            throw new Error(`npm install failed for ${spec.label} (exit code ${result.status})`);
        }
        return { id: component, ...spec, path: path.relative(path.join(ROOT, 'windows-app'), target) };
    }

    const archive = await ensureArchive(component, spec);
    const staging = fs.mkdtempSync(path.join(os.tmpdir(), `linkeasy-${component}-`));

    log(`Unpacking ${spec.label}…`);
    if (archive.endsWith('.tgz')) {
        execFileSync('tar', ['-xzf', archive, '-C', staging]);
    } else if (process.platform === 'win32') {
        execFileSync('powershell', ['-NoProfile', '-Command',
            `Expand-Archive -LiteralPath '${archive}' -DestinationPath '${staging}' -Force`]);
    } else {
        execFileSync('unzip', ['-q', archive, '-d', staging]);
    }

    // Flatten a single top-level directory (Node's and FFmpeg's archives have one).
    let source = staging;
    if (spec.flatten) {
        const entries = fs.readdirSync(staging, { withFileTypes: true });
        const onlyDir = entries.length === 1 && entries[0].isDirectory();
        if (onlyDir) {
            source = path.join(staging, entries[0].name);
            // FFmpeg nests a second level (bin/).
            const inner = fs.readdirSync(source, { withFileTypes: true });
            const innerBin = inner.find((e) => e.isDirectory() && e.name === 'bin');
            if (innerBin && component === 'ffmpeg') {
                source = path.join(source, 'bin');
            }
        }
    }

    copyInto(source, target, spec.keep);
    fs.rmSync(staging, { recursive: true, force: true });

    log(`${spec.label} ready in ${path.relative(ROOT, target)}`);
    return { id: component, ...spec, path: path.relative(path.join(ROOT, 'windows-app'), target) };
}

function copyInto(source, target, keep) {
    for (const entry of fs.readdirSync(source, { withFileTypes: true })) {
        if (keep && !keep.includes(entry.name) && entry.isFile()) {
            continue;
        }
        const from = path.join(source, entry.name);
        const to = path.join(target, entry.name);
        if (entry.isDirectory()) {
            fs.mkdirSync(to, { recursive: true });
            copyInto(from, to, null);
        } else {
            fs.copyFileSync(from, to);
        }
    }
}

async function writeManifest() {
    const manifest = { generatedAt: new Date().toISOString(), components: {} };

    const directories = ['node', 'playwright', 'chromium', 'ffmpeg', 'worker'];
    for (const dir of directories) {
        const full = path.join(RUNTIME, dir);
        if (!fs.existsSync(full)) {
            continue;
        }
        const digest = await sha256Tree(full);

        // Version markers the runtime verifier expects.
        if (dir === 'chromium') {
            fs.writeFileSync(path.join(full, 'version.json'), JSON.stringify({
                version: SOURCES.chromium.version,
            }, null, 2));
        }
        if (dir === 'worker') {
            const pkg = require(path.join(ROOT, 'windows-app', 'package.json'));
            fs.writeFileSync(path.join(full, 'version.json'), JSON.stringify({
                version: pkg.version,
            }, null, 2));
        }

        manifest.components[dir] = digest;
        log(`${dir}: ${digest.digest.slice(0, 16)}… (${digest.files} files, ${(digest.bytes / 1048576).toFixed(1)} MB)`);
    }

    fs.writeFileSync(path.join(RUNTIME, 'manifest.json'), JSON.stringify(manifest, null, 2));
    log(`Manifest written: ${path.relative(ROOT, path.join(RUNTIME, 'manifest.json'))}`);
    return manifest;
}

async function writePayloads() {
    fs.mkdirSync(PAYLOAD, { recursive: true });
    log('Writing repair payloads…');

    for (const component of ['node', 'playwright', 'chromium', 'ffmpeg']) {
        const source = path.join(RUNTIME, component);
        if (!fs.existsSync(source)) {
            continue;
        }
        const zip = path.join(PAYLOAD, `${component}.zip`);

        if (process.platform === 'win32') {
            execFileSync('powershell', ['-NoProfile', '-Command',
                `Compress-Archive -Path '${source}\\*' -DestinationPath '${zip}' -Force`]);
        } else {
            execFileSync('zip', ['-qr', zip, '.'], { cwd: source });
        }

        const digest = await sha256File(zip);
        log(`${component}.zip — ${digest.slice(0, 16)}…`);
    }
}

async function main() {
    log(`Building the bundled runtime for LinkEasy Publisher ${require(path.join(ROOT, 'windows-app', 'package.json')).version}`);
    log(`Target: ${path.relative(ROOT, RUNTIME)}`);

    fs.mkdirSync(RUNTIME, { recursive: true });
    fs.mkdirSync(CACHE, { recursive: true });

    const components = ONLY ? [ONLY] : ['node', 'playwright', 'chromium', 'ffmpeg'];
    for (const component of components) {
        await buildComponent(component);
    }

    // The worker source travels with the application itself.
    const workerTarget = path.join(RUNTIME, 'worker');
    fs.rmSync(workerTarget, { recursive: true, force: true });
    fs.mkdirSync(workerTarget, { recursive: true });
    copyInto(path.join(ROOT, 'windows-app', 'src'), workerTarget, null);

    await writeManifest();
    if (WANT_PAYLOAD) {
        await writePayloads();
    }

    log('Done. Next: node verify-runtime.js, then npm run dist in windows-app/.');
}

main().catch((error) => {
    console.error(`\n[runtime] build failed: ${error.message}\n`);
    process.exit(1);
});
