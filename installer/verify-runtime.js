#!/usr/bin/env node
'use strict';

/**
 * Pre-release gate: prove that the bundled runtime actually works.
 *
 * Run this on a clean Windows VM *before* shipping. It executes every bundled
 * binary and confirms the hashes match the manifest, so a broken or tampered
 * runtime is caught here rather than on a customer's machine.
 *
 *   node verify-runtime.js
 *   node verify-runtime.js --require-windows   # fail on a non-Windows host
 */

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');

const ROOT = path.resolve(__dirname, '..');
const RUNTIME = path.join(ROOT, 'windows-app', 'runtime');
const requireWindows = process.argv.includes('--require-windows');

const problems = [];
const results = [];

function sha256File(file) {
    const hash = crypto.createHash('sha256');
    const data = fs.readFileSync(file);
    hash.update(data);
    return hash.digest('hex');
}

function tryRun(label, command, args, expect) {
    try {
        const output = String(execFileSync(command, args, { encoding: 'utf8', timeout: 60000, windowsHide: true })).split('\n')[0].trim();
        const ok = !expect || expect.test(output);
        results.push({ label, ok, output });
        if (!ok) {
            problems.push(`${label} produced unexpected output: ${output}`);
        }
    } catch (error) {
        results.push({ label, ok: false, output: String(error.message) });
        problems.push(`${label} could not be executed: ${error.message}`);
    }
}

function main() {
    if (!fs.existsSync(RUNTIME)) {
        console.error(`No runtime found at ${RUNTIME}\nRun: node build-runtime.js`);
        process.exit(1);
    }

    if (requireWindows && process.platform !== 'win32') {
        problems.push('--require-windows was requested but this host is not Windows.');
    }

    console.log('Runtime verification\n');

    // ---- manifest -------------------------------------------------------
    const manifestPath = path.join(RUNTIME, 'manifest.json');
    if (!fs.existsSync(manifestPath)) {
        problems.push('runtime/manifest.json is missing — the application cannot verify its own runtime.');
    } else {
        const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
        console.log(`Manifest generated ${manifest.generatedAt}`);

        for (const [component, expected] of Object.entries(manifest.components || {})) {
            const dir = path.join(RUNTIME, component);
            const actual = walkDigest(dir);
            if (actual !== expected) {
                problems.push(`${component} hash mismatch (manifest ${expected.slice(0, 12)}…, on disk ${actual.slice(0, 12)}…)`);
                console.log(`  × ${component} — hash mismatch`);
            } else {
                console.log(`  ✓ ${component} — hash matches`);
            }
        }
    }

    // ---- binaries -------------------------------------------------------
    console.log('\nBinaries');

    const node = path.join(RUNTIME, 'node', process.platform === 'win32' ? 'node.exe' : 'bin/node');
    if (fs.existsSync(node)) {
        tryRun('Node runtime', node, ['--version'], /^v20\./);
    } else {
        problems.push('runtime/node is missing.');
    }

    const ffmpeg = path.join(RUNTIME, 'ffmpeg', process.platform === 'win32' ? 'ffmpeg.exe' : 'bin/ffmpeg');
    if (fs.existsSync(ffmpeg)) {
        tryRun('FFmpeg', ffmpeg, ['-version'], /^ffmpeg version/i);
    } else {
        problems.push('runtime/ffmpeg/ffmpeg is missing.');
    }

    const ffprobe = path.join(RUNTIME, 'ffmpeg', process.platform === 'win32' ? 'ffprobe.exe' : 'bin/ffprobe');
    if (fs.existsSync(ffprobe)) {
        tryRun('FFprobe', ffprobe, ['-version'], /^ffprobe version/i);
    } else {
        problems.push('runtime/ffmpeg/ffprobe is missing.');
    }

    // ---- chromium -------------------------------------------------------
    console.log('\nChromium');
    const chromiumCandidates = [
        path.join(RUNTIME, 'chromium', 'chrome-win', 'chrome.exe'),
        path.join(RUNTIME, 'chromium', 'chrome-win64', 'chrome.exe'),
    ];
    const chromium = chromiumCandidates.find((candidate) => fs.existsSync(candidate));
    if (chromium) {
        console.log(`  ✓ found ${path.relative(ROOT, chromium)}`);
        const versionFile = path.join(RUNTIME, 'chromium', 'version.json');
        if (fs.existsSync(versionFile)) {
            console.log(`    revision ${JSON.parse(fs.readFileSync(versionFile, 'utf8')).version}`);
        }
    } else {
        problems.push('runtime/chromium has no chrome.exe — Playwright launches would fail.');
    }

    // ---- playwright -----------------------------------------------------
    console.log('\nPlaywright');
    const playwrightPkg = path.join(RUNTIME, 'playwright', 'node_modules', 'playwright', 'package.json');
    if (fs.existsSync(playwrightPkg)) {
        const pkg = JSON.parse(fs.readFileSync(playwrightPkg, 'utf8'));
        const expected = require(path.join(ROOT, 'windows-app', 'package.json')).dependencies.playwright;
        console.log(`  ✓ playwright ${pkg.version}`);
        if (pkg.version !== expected) {
            problems.push(`Playwright version mismatch: runtime has ${pkg.version}, the app expects ${expected}.`);
        }
    } else {
        problems.push('runtime/playwright is missing or incomplete.');
    }

    // ---- worker ---------------------------------------------------------
    console.log('\nWorker');
    const workerEntry = path.join(RUNTIME, 'worker', 'worker', 'worker.js');
    if (fs.existsSync(workerEntry)) {
        console.log('  ✓ worker sources are bundled');
    } else {
        problems.push('runtime/worker is missing — the installed app would have nothing to run.');
    }

    // ---- verdict --------------------------------------------------------
    console.log('\n' + '-'.repeat(60));
    if (problems.length === 0) {
        console.log('Runtime OK. Safe to build the installer.');
        process.exit(0);
    }

    console.log(`${problems.length} problem(s):`);
    problems.forEach((problem) => console.log(`  - ${problem}`));
    console.log('\nDo not ship this build.');
    process.exit(1);
}

function walkDigest(dir) {
    if (!fs.existsSync(dir)) {
        return '';
    }
    const hash = crypto.createHash('sha256');
    const files = [];

    const walk = (current, prefix) => {
        for (const name of fs.readdirSync(current).sort()) {
            const full = path.join(current, name);
            const stat = fs.statSync(full);
            if (stat.isDirectory()) {
                walk(full, path.join(prefix, name));
            } else {
                files.push({ rel: path.join(prefix, name), size: stat.size, file: full });
            }
        }
    };
    walk(dir, '');

    for (const file of files) {
        hash.update(file.rel.replace(/\\/g, '/'));
        hash.update(':');
        hash.update(String(file.size));
        hash.update(':');
        hash.update(sha256File(file.file));
        hash.update('\n');
    }

    return hash.digest('hex');
}

main();
