'use strict';

/**
 * Local settings store: defaults, overlaid by the user's saved file, overlaid
 * by whatever the server last sent in /worker/config.
 */

const fs = require('fs');
const paths = require('../config/paths');
const defaults = require('../config/defaults');

let serverOverrides = {};
let cached = null;

function deepMerge(base, extra) {
    if (!extra || typeof extra !== 'object') {
        return base;
    }
    const out = Array.isArray(base) ? [...base] : { ...base };
    for (const [key, value] of Object.entries(extra)) {
        const isPlain = value && typeof value === 'object' && !Array.isArray(value);
        out[key] = isPlain && base && typeof base[key] === 'object' && !Array.isArray(base[key])
            ? deepMerge(base[key], value)
            : value;
    }
    return out;
}

function loadUserFile() {
    const file = paths.settingsFile();
    if (!fs.existsSync(file)) {
        return {};
    }
    try {
        return JSON.parse(fs.readFileSync(file, 'utf8'));
    } catch {
        return {};
    }
}

function get(keyPath, fallback) {
    if (cached === null) {
        cached = deepMerge(deepMerge(defaults, loadUserFile()), { server: {}, worker: {}, diagnostics: {}, media: {} });
    }
    const segments = keyPath.split('.');
    let cursor = cached;
    for (const segment of segments) {
        if (cursor === undefined || cursor === null || !(segment in cursor)) {
            return fallback;
        }
        cursor = cursor[segment];
    }
    return cursor === undefined ? fallback : cursor;
}

function set(keyPath, value) {
    const segments = keyPath.split('.');
    const user = loadUserFile();
    let cursor = user;
    for (let i = 0; i < segments.length - 1; i += 1) {
        cursor[segments[i]] = cursor[segments[i]] || {};
        cursor = cursor[segments[i]];
    }
    cursor[segments[segments.length - 1]] = value;

    // The config folder is created on first run, but a settings write must never
    // depend on that having happened already.
    const file = paths.settingsFile();
    fs.mkdirSync(require('path').dirname(file), { recursive: true });
    fs.writeFileSync(file, JSON.stringify(user, null, 2));
    cached = null;
    return value;
}

/** Apply the config bundle returned by the server (GET /worker/config). */
function applyServerConfig(bundle) {
    if (!bundle || typeof bundle !== 'object') {
        return;
    }

    serverOverrides = {
        worker: {
            maxConcurrentJobs: toInt(bundle.max_concurrent_jobs),
            maxConcurrentBrowsers: toInt(bundle.max_concurrent_browsers),
            maxJobsPerWorker: toInt(bundle.max_jobs_per_worker),
            idleBrowserTimeoutSeconds: toInt(bundle.idle_browser_timeout_s),
            startWithWindows: toBool(bundle.start_with_windows),
            minimizeToTray: toBool(bundle.minimize_to_tray),
        },
        diagnostics: {
            traceMode: bundle.trace_mode,
            debugMode: toBool(bundle.debug_mode),
            verboseLogs: toBool(bundle.verbose_logs),
            captureScreenshots: toBool(bundle.capture_screenshots),
            traceRetentionDays: toInt(bundle.retention_traces_d),
            screenshotRetentionDays: toInt(bundle.retention_screenshots_d),
        },
        media: {
            strategy: bundle.media_strategy,
            cleanupAfterPublish: toBool(bundle.cleanup_after_publish),
        },
    };

    // Drop undefined values so defaults survive.
    serverOverrides = JSON.parse(JSON.stringify(serverOverrides));

    cached = deepMerge(deepMerge(defaults, loadUserFile()), serverOverrides);
    return serverOverrides;
}

function serverConfig() {
    return serverOverrides;
}

function all() {
    if (cached === null) {
        cached = deepMerge(defaults, loadUserFile());
    }
    return cached;
}

function toInt(value) {
    const n = Number.parseInt(value, 10);
    return Number.isFinite(n) ? n : undefined;
}

function toBool(value) {
    if (value === undefined || value === null || value === '') {
        return undefined;
    }
    return value === true || value === '1' || value === 1 || value === 'true';
}

module.exports = { get, set, all, applyServerConfig, serverConfig, deepMerge };
