'use strict';

/**
 * Path resolution for both distribution modes.
 *
 *   Standard install
 *     binaries : C:\Program Files\LinkEasyPublisher\        (per machine)
 *     user data: %LOCALAPPDATA%\LinkEasyPublisher\          (per Windows user)
 *
 *   Portable
 *     everything beside the executable, under LinkEasyPublisherData\
 *
 * Data is never written next to the binaries in an installed copy, so each
 * Windows user gets isolated profiles, logs, settings and worker identity
 * (prompt §51).
 */

const os = require('os');
const path = require('path');
const fs = require('fs');

const IS_WINDOWS = process.platform === 'win32';
const PORTABLE = process.env.LINKEASY_PORTABLE === '1' || fs.existsSync(path.join(__dirname, '..', '..', 'PORTABLE'));

function appRoot() {
    // Installed: resources/app.asar -> resources ; Portable: the extracted folder
    if (process.resourcesPath && fs.existsSync(path.join(process.resourcesPath, 'runtime'))) {
        return process.resourcesPath;
    }
    return path.resolve(__dirname, '..', '..');
}

function dataRoot() {
    if (process.env.LINKEASY_DATA_DIR) {
        return process.env.LINKEASY_DATA_DIR;
    }
    if (PORTABLE) {
        return path.join(path.dirname(process.execPath), 'LinkEasyPublisherData');
    }
    const base = process.env.LOCALAPPDATA || path.join(os.homedir(), 'AppData', 'Local');
    return path.join(base, 'LinkEasyPublisher');
}

function ensure(dir) {
    if (!fs.existsSync(dir)) {
        fs.mkdirSync(dir, { recursive: true });
    }
    return dir;
}

const paths = {
    isWindows: IS_WINDOWS,
    portable: PORTABLE,
    appRoot,
    dataRoot,

    /** Bundled runtime components: node, playwright, chromium, ffmpeg. */
    runtime: (component) => component
        ? path.join(appRoot(), 'runtime', component)
        : path.join(appRoot(), 'runtime'),

    nodeBinary: () => path.join(appRoot(), 'runtime', 'node', IS_WINDOWS ? 'node.exe' : 'bin/node'),
    ffmpegBinary: () => path.join(appRoot(), 'runtime', 'ffmpeg', IS_WINDOWS ? 'ffmpeg.exe' : 'bin/ffmpeg'),
    ffprobeBinary: () => path.join(appRoot(), 'runtime', 'ffmpeg', IS_WINDOWS ? 'ffprobe.exe' : 'bin/ffprobe'),
    chromiumDir: () => path.join(appRoot(), 'runtime', 'chromium'),

    config: () => ensure(path.join(dataRoot(), 'config')),
    profiles: () => ensure(path.join(dataRoot(), 'profiles')),
    profile: (profileRef) => ensure(path.join(dataRoot(), 'profiles', sanitise(profileRef))),
    logs: () => ensure(path.join(dataRoot(), 'logs')),
    cache: () => ensure(path.join(dataRoot(), 'cache')),
    downloads: () => ensure(path.join(dataRoot(), 'downloads')),
    screenshots: () => ensure(path.join(dataRoot(), 'screenshots')),
    traces: () => ensure(path.join(dataRoot(), 'traces')),
    crashDumps: () => ensure(path.join(dataRoot(), 'crashdumps')),

    /** Per-user credential file (DPAPI-protected). */
    credentialFile: () => path.join(dataRoot(), 'config', 'credentials.bin'),
    settingsFile: () => path.join(dataRoot(), 'config', 'settings.json'),
    workerStateFile: () => path.join(dataRoot(), 'config', 'worker.json'),
    machineFile: () => path.join(dataRoot(), 'config', 'machine.json'),
};

function sanitise(value) {
    return String(value).replace(/[^A-Za-z0-9._-]/g, '_').slice(0, 64) || 'profile';
}

module.exports = paths;
