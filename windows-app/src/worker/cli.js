#!/usr/bin/env node
'use strict';

/**
 * Headless worker entry point.
 *
 * The desktop app starts the worker in-process, but this CLI exists for
 * diagnostics, for the repair tools and for advanced operators who want to run
 * it under a service wrapper. Every command is also reachable from the tray app,
 * so a normal user never needs a terminal (prompt §74).
 *
 *   linkeasy-worker start                 run until Ctrl+C
 *   linkeasy-worker once                  claim and run every available job, then exit
 *   linkeasy-worker status                print worker, browser and queue state
 *   linkeasy-worker enrol --server=…      register this PC (asks for credentials)
 *   linkeasy-worker verify                check the bundled runtime
 *   linkeasy-worker repair                restore damaged runtime components
 *   linkeasy-worker diagnostics           write a support bundle path
 *   linkeasy-worker signout               remove the stored worker token
 */

const os = require('os');
const fs = require('fs');
const path = require('path');

const args = parseArgs(process.argv.slice(2));
const command = args._[0] || 'status';

const { Worker } = require('./worker');
const runtime = require('../runtime/dependency-manager');
const credentials = require('../security/credential-store');
const paths = require('../config/paths');
const settings = require('../config/settings');
const { Logger } = require('../logging/logger');

const log = new Logger({ channel: 'app' });

async function main() {
    switch (command) {
        case 'start':
            return start();
        case 'once':
            return runOnce();
        case 'status':
            return status();
        case 'enrol':
        case 'enroll':
            return enrol();
        case 'verify':
            return verifyRuntime();
        case 'repair':
            return repairRuntime();
        case 'diagnostics':
            return diagnostics();
        case 'signout':
            return signOut();
        case 'help':
        case '--help':
        case '-h':
            return help();
        default:
            console.error(`Unknown command: ${command}`);
            help();
            process.exitCode = 2;
    }
}

async function start() {
    const worker = new Worker();

    worker.on('state', (state) => log.info(`Worker state: ${state.state}`, { active: state.activeJobs.length }));
    worker.on('job:start', ({ jobId, job }) => log.info(`Job ${jobId} started`, { type: job.job_type, page: job.page?.name }));
    worker.on('job:done', (info) => log.info(`Job ${info.jobId} finished`, info));
    worker.on('challenge', (info) => log.warn(`Operator action needed: ${info.code}`, info));
    worker.on('paused', (info) => log.warn('Worker paused', info));
    worker.on('offline', () => log.warn('Working offline — the server is unreachable.'));
    worker.on('unauthorised', ({ message }) => log.error(message));

    const state = await worker.start();
    log.info('Worker is running.', { workerId: state.workerId, server: state.serverUrl });

    const shutdown = async () => {
        log.info('Shutting down…');
        await worker.stop();
        process.exit(0);
    };
    process.on('SIGINT', shutdown);
    process.on('SIGTERM', shutdown);

    if (args.pauseAfterAll) {
        const timer = setInterval(async () => {
            if (worker.activeJobs.size === 0) {
                clearInterval(timer);
                await shutdown();
            }
        }, 1000);
    }
}

async function runOnce() {
    const worker = new Worker();
    await worker.start();

    for (let i = 0; i < (args.rounds || 4); i += 1) {
        await worker.poll();
        if (worker.activeJobs.size === 0) {
            break;
        }
        while (worker.activeJobs.size > 0) {
            await new Promise((resolve) => setTimeout(resolve, 500));
        }
    }

    await worker.stop({ drain: false });
    const status = worker.status();
    printJson({
        ok: true,
        claimed: status.stats.claimed,
        published: status.stats.published,
        failed: status.stats.failed,
    });
}

async function status() {
    const worker = new Worker();
    const info = worker.status();
    printJson({
        enrolled: Boolean(worker.credentials?.token),
        worker_id: info.workerId,
        server: info.serverUrl,
        state: info.state,
        online: info.online,
        active_jobs: info.activeJobs,
        browsers: info.browsers,
        limits: info.limits,
        stats: info.stats,
    });
}

async function enrol() {
    const serverUrl = args.server || settings.get('server.baseUrl', '');
    const email = args.email || process.env.LINKEASY_EMAIL;
    const password = args.password || process.env.LINKEASY_PASSWORD;

    if (!serverUrl || !email || !password) {
        console.error('Usage: linkeasy-worker enrol --server=https://your-server --email=you@example.com --password=…');
        console.error('Prefer the tray app: it signs you in without putting your password on a command line.');
        process.exitCode = 2;
        return;
    }

    const worker = new Worker();
    const response = await worker.enrol({ serverUrl, email, password, name: args.name || os.hostname() });

    printJson({
        ok: true,
        worker: response.worker.id,
        name: response.worker.name,
        status: response.worker.status,
        protection: credentials.protectionLevel(),
    });
}

async function verifyRuntime() {
    const report = await runtime.report();
    console.log(`\nRuntime check — ${report.ok ? 'all components healthy' : 'problems found'}\n`);
    report.lines.forEach((line) => console.log(`  ${line}`));
    if (report.problems.length > 0) {
        console.log('\nProblems:');
        report.problems.forEach((problem) => console.log(`  - ${problem.label}: ${problem.detail}`));
        console.log('\nRun: linkeasy-worker repair');
    }
    printJson(report.summary);
}

async function repairRuntime() {
    const result = await runtime.repair();
    printJson({ ok: result.after.ok, repaired: result.repaired });
}

async function diagnostics() {
    const report = await runtime.report();
    const file = path.join(paths.logs(), `diagnostics-${Date.now()}.json`);

    fs.writeFileSync(file, JSON.stringify({
        generatedAt: new Date().toISOString(),
        platform: { os: os.type(), release: os.release(), arch: os.arch(), hostname: os.hostname() },
        paths: {
            appRoot: paths.appRoot(),
            dataRoot: paths.dataRoot(),
            portable: paths.portable,
        },
        credentialProtection: credentials.protectionLevel(),
        runtime: report.summary,
        components: report.components.map((c) => ({ id: c.id, status: c.status, version: c.version })),
        counts: {
            profiles: countFiles(paths.profiles()),
            cachedMedia: countFiles(paths.cache()),
            screenshots: countFiles(paths.screenshots()),
            traces: countFiles(paths.traces()),
        },
        // Log tails are redacted by the logger before they reach disk.
        workerLogTail: readTail(path.join(paths.logs(), 'worker.log'), 60),
        errorLogTail: readTail(path.join(paths.logs(), 'errors.log'), 40),
    }, null, 2));

    console.log(`Diagnostics written to:\n  ${file}`);
}

async function signOut() {
    credentials.clear();
    printJson({ ok: true, message: 'This PC is no longer enrolled. Start the app to connect it again.' });
}

function help() {
    console.log(`LinkEasy Publisher worker ${settings.get('app.workerVersion', '1.0.0')}

  start                 run the worker until interrupted
  once                  process everything available, then exit
  status                show worker, browser and queue state
  enrol --server=…      register this PC with your workspace
  verify                check Node, Playwright, Chromium and FFmpeg
  repair                restore damaged runtime components
  diagnostics           write a support bundle to the logs folder
  signout               remove the stored worker token
`);
}

function countFiles(dir) {
    try {
        return fs.readdirSync(dir).length;
    } catch {
        return 0;
    }
}

function readTail(file, lines) {
    try {
        return fs.readFileSync(file, 'utf8').trim().split('\n').slice(-lines);
    } catch {
        return [];
    }
}

function printJson(value) {
    console.log(JSON.stringify(value, null, 2));
}

function parseArgs(argv) {
    const out = { _: [] };
    for (const item of argv) {
        if (item.startsWith('--')) {
            const [key, ...rest] = item.slice(2).split('=');
            out[camel(key)] = rest.length ? rest.join('=') : true;
        } else {
            out._.push(item);
        }
    }
    return out;
}

function camel(value) {
    return value.replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());
}

main().catch((error) => {
    log.error('The command failed', { error: String(error) });
    console.error(`\n${error.message}\n`);
    process.exitCode = 1;
});
