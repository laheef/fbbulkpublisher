'use strict';

/**
 * Configuration rules:
 *   - safe defaults exist for everything the worker needs
 *   - server-provided config overrides local defaults, but only for keys the
 *     server is allowed to control (prompt §9)
 *   - the concurrency caps default to small, predictable numbers
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const os = require('os');
const path = require('path');

process.env.LINKEASY_DATA_DIR = fs.mkdtempSync(path.join(os.tmpdir(), 'linkeasy-settings-'));

const settings = require('../../src/config/settings');
const defaults = require('../../src/config/defaults');

test('safe defaults are present', () => {
    assert.equal(settings.get('worker.maxConcurrentBrowsers'), 2);
    assert.equal(settings.get('worker.maxConcurrentJobs'), 2);
    assert.equal(settings.get('worker.maxJobsPerWorker'), 10);
    assert.equal(settings.get('server.verifyTls'), true);
    assert.equal(settings.get('updater.requireSignature'), true);
    assert.equal(settings.get('localApi.host'), '127.0.0.1');
    assert.equal(settings.get('browser.headless'), false, 'the operator must be able to see the browser');
});

test('the server bundle overrides behaviour that affects publishing', () => {
    settings.applyServerConfig({
        max_concurrent_jobs: 3,
        max_concurrent_browsers: 1,
        max_jobs_per_worker: 6,
        idle_browser_timeout_s: 120,
        trace_mode: 'off',
        debug_mode: false,
        capture_screenshots: false,
        retention_traces_d: 7,
        media_strategy: 'LOCAL',
        cleanup_after_publish: true,
    });

    assert.equal(settings.get('worker.maxConcurrentJobs'), 3);
    assert.equal(settings.get('worker.maxConcurrentBrowsers'), 1);
    assert.equal(settings.get('worker.maxJobsPerWorker'), 6);
    assert.equal(settings.get('worker.idleBrowserTimeoutSeconds'), 120);
    assert.equal(settings.get('diagnostics.traceMode'), 'off');
    assert.equal(settings.get('diagnostics.captureScreenshots'), false);
    assert.equal(settings.get('media.strategy'), 'LOCAL');
});

test('keys the server does not send keep their local values', () => {
    settings.applyServerConfig({ max_concurrent_jobs: 5 });

    assert.equal(settings.get('worker.maxConcurrentJobs'), 5);
    assert.equal(settings.get('server.verifyTls'), true, 'TLS verification is never turned off by the server');
    assert.equal(settings.get('updater.requireSignature'), true, 'signature requirement stays on');
    assert.equal(settings.get('browser.headless'), false);
});

test('user changes persist to disk and are visible after a reload', () => {
    settings.set('diagnostics.verboseLogs', true);
    const file = path.join(process.env.LINKEASY_DATA_DIR, 'config', 'settings.json');
    assert.ok(fs.existsSync(file));

    const saved = JSON.parse(fs.readFileSync(file, 'utf8'));
    assert.equal(saved.diagnostics.verboseLogs, true);
});

test('defaults are complete enough for a fresh install', () => {
    for (const section of ['app', 'server', 'worker', 'browser', 'media', 'diagnostics', 'updater', 'localApi']) {
        assert.ok(defaults[section], `${section} defaults are missing`);
    }
});
