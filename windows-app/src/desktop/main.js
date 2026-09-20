'use strict';

/**
 * Electron main process.
 *
 * UI → Electron → Node bridge → worker → Playwright → Chromium → Facebook
 * (prompt §44). The operator sees a tray icon and a normal window; there is no
 * console window, no terminal and nothing to configure by hand (prompt §74).
 */

const path = require('path');
const os = require('os');
const fs = require('fs');
const { app, ipcMain, shell, dialog, BrowserWindow } = require('electron');

const { WindowController } = require('./window');
const { TrayController } = require('./tray');
const { LocalApi } = require('./local-api');
const { Notifier } = require('./notifications');
const { Worker, STATE } = require('../worker/worker');
const autostart = require('./autostart');
const updater = require('../updater/updater');
const runtime = require('../runtime/dependency-manager');
const credentials = require('../security/credential-store');
const settings = require('../config/settings');
const paths = require('../config/paths');
const { Logger } = require('../logging/logger');

const log = new Logger({ channel: 'app' });

// One instance only: a second launch just reveals the running window.
if (!app.requestSingleInstanceLock()) {
    app.quit();
} else {
    bootstrap();
}

let windowController = null;
let trayController = null;
let localApi = null;
let notifier = null;
let worker = null;
let quitting = false;

function bootstrap() {
    app.setAppUserModelId('com.linkeasy.publisher');   // required for Windows toasts

    app.on('second-instance', () => {
        windowController?.show();
    });

    app.on('window-all-closed', (event) => {
        // The worker keeps running in the tray; only an explicit quit exits.
        event.preventDefault?.();
    });

    app.on('before-quit', async (event) => {
        if (quitting) {
            return;
        }
        event.preventDefault();
        await shutdown();
    });

    app.whenReady().then(async () => {
        fs.mkdirSync(paths.config(), { recursive: true });

        notifier = new Notifier({
            enabled: true,
            onClick: () => windowController?.show(),
            onAction: () => windowController?.show(),
        });

        // ---- local dashboard API (loopback + token, prompt §30, §76) ----
        localApi = new LocalApi({
            worker: null,
            notifier,
            onControl: handleControl,
        });

        let apiInfo;
        try {
            apiInfo = await localApi.start();
        } catch (error) {
            log.error('The local dashboard could not start.', { error: String(error) });
            dialog.showErrorBox('LinkEasy Publisher', `The local dashboard could not start.\n\n${error.message}`);
            app.exit(1);
            return;
        }

        windowController = new WindowController();
        windowController.create({ preload: path.join(__dirname, 'preload.js'), localApi });

        trayController = new TrayController({
            window: windowController,
            worker: null,
            notifier,
            onQuit: () => shutdown({ quitApp: true }),
            onReconnect: () => windowController.show('accounts'),
            onRepair: () => runRepair(),
            onCheckUpdates: () => checkForUpdates({ manual: true }),
            onOpenDashboard: () => openWorkspaceDashboard(),
            onOpenLogs: () => shell.openPath(paths.logs()),
        });
        trayController.create();

        registerIpc();

        await firstRunOrStart();

        updater.init({
            notifier,
            isIdle: () => !worker || worker.activeJobs.size === 0,
            window: windowController,
        });
        await updater.autoCheck().catch(() => {});

        log.info('LinkEasy Publisher is ready.', {
            version: app.getVersion(),
            dataRoot: paths.dataRoot(),
            portable: paths.portable,
            apiPort: apiInfo.port,
        });
    });
}

/** First launch walks the operator through connect → verify → ready. */
async function firstRunOrStart() {
    const runtimeReport = await runtime.report();
    const configured = Boolean(settings.get('server.baseUrl', '')) && Boolean(credentials.loadWorkerCredentials());

    windowController.send('runtime:report', runtimeReport);

    if (!runtimeReport.ok) {
        notifier.runtimeProblem({
            problems: runtimeReport.problems.length,
        });
        windowController.show('runtime');
        // A broken runtime cannot publish; we still start the UI so the operator
        // can press Repair (prompt §47).
        return;
    }

    if (!configured) {
        windowController.show('welcome');
        return;
    }

    await startWorker();
}

async function startWorker() {
    if (worker) {
        return worker.status();
    }

    worker = new Worker();

    worker.on('state', (status) => {
        windowController?.send('worker:state', status);
        trayController?.refresh();
    });
    worker.on('job:start', (info) => windowController?.send('worker:job', { kind: 'start', ...info }));
    worker.on('job:progress', (info) => windowController?.send('worker:job-progress', info));
    worker.on('job:done', (info) => windowController?.send('worker:job', { kind: 'done', ...info }));
    worker.on('challenge', (info) => windowController?.send('worker:challenge', info));
    worker.on('notify', (payload) => notifier.show(payload));
    worker.on('unauthorised', ({ message }) => {
        notifier.show({ title: 'This PC was disconnected', body: message, level: 'error', urgent: true });
        windowController?.show('settings');
    });
    worker.on('offline', () => notifier.show({
        title: 'Working offline',
        body: 'Your workspace is unreachable. Publishing will resume automatically.',
    }));

    // The local API mirrors the same worker object.
    localApi.worker = worker;
    trayController.worker = worker;
    trayController.refresh();

    try {
        const status = await worker.start();
        log.info('Worker started', { state: status.state });
        return status;
    } catch (error) {
        log.error('The worker could not start.', { error: String(error) });
        notifier.show({
            title: 'Publishing is not running',
            body: error.message,
            level: 'error',
            urgent: true,
        });
        return { state: STATE.ERROR, error: error.message };
    }
}

async function handleControl(action, payload) {
    switch (action) {
        case 'check-updates':
            return checkForUpdates({ manual: true });
        case 'install-update':
            return updater.installNow();
        case 'settings': {
            const applied = applySettingsPatch(payload || {});
            return applied;
        }
        default:
            return { ok: false, error: `Unknown action: ${action}` };
    }
}

function applySettingsPatch(patch) {
    const applied = {};

    // Only behaviour-local settings can be changed here; anything that affects
    // publishing policy comes from the dashboard (prompt §9).
    const allowed = [
        'worker.startWithWindows',
        'worker.minimizeToTray',
        'worker.autoStartWorker',
        'diagnostics.verboseLogs',
        'diagnostics.captureScreenshots',
        'media.cleanupAfterPublish',
        'media.maxLocalCacheBytes',
        'updater.enabled',
    ];

    for (const [key, value] of Object.entries(patch)) {
        if (!allowed.includes(key)) {
            continue;
        }
        settings.set(key, value);
        applied[key] = value;
    }

    if ('worker.startWithWindows' in applied) {
        autostart.setEnabled(Boolean(applied['worker.startWithWindows']));
    }

    if ('diagnostics.verboseLogs' in applied && worker) {
        worker.applyLocalSettings();
    }

    log.info('Settings updated from the app window.', { keys: Object.keys(applied) });
    return applied;
}

async function checkForUpdates({ manual = false } = {}) {
    const result = await updater.check({ manual });
    windowController?.send('update:status', result);
    return result;
}

async function runRepair() {
    notifier.show({ title: 'Repairing components', body: 'This can take a minute. Nothing is published during a repair.' });
    const result = await runtime.repair();
    windowController?.send('runtime:report', result.after);
    notifier.show({
        title: result.after.ok ? 'Repair finished' : 'Repair needs attention',
        body: result.after.ok
            ? 'Everything checks out. Publishing can continue.'
            : `${result.after.problems.length} component(s) still need attention.`,
        level: result.after.ok ? 'info' : 'error',
    });
    return result;
}

async function openWorkspaceDashboard() {
    const base = settings.get('server.baseUrl', '');
    if (!base) {
        windowController.show('welcome');
        return;
    }
    await shell.openExternal(`${base.replace(/\/+$/, '')}/dashboard`);
}

/** Register every channel the preload bridge exposes. */
function registerIpc() {
    ipcMain.handle('app:version', () => ({
        app: app.getVersion(),
        worker: settings.get('app.workerVersion', '1.0.0'),
        electron: process.versions.electron,
        node: process.versions.node,
        chrome: process.versions.chrome,
        platform: `${os.type()} ${os.release()}`,
        portable: paths.portable,
        dataRoot: paths.dataRoot(),
    }));

    ipcMain.handle('worker:status', () => (worker ? worker.status() : {
        state: STATE.OFFLINE,
        activeJobs: [],
        browsers: [],
        stats: {},
        online: false,
    }));
    ipcMain.handle('worker:pause', () => {
        if (!worker) {
            return { ok: false, error: 'The worker is not running.' };
        }
        trayController.pauseWorker();
        return { ok: true, data: worker.status() };
    });
    ipcMain.handle('worker:resume', () => {
        if (!worker) {
            return { ok: false, error: 'The worker is not running.' };
        }
        worker.resume();
        return { ok: true, data: worker.status() };
    });
    ipcMain.handle('worker:heartbeat', async () => {
        if (!worker) {
            return { ok: false, error: 'The worker is not running.' };
        }
        await worker.heartbeat();
        return { ok: true, data: worker.status() };
    });

    ipcMain.handle('runtime:report', () => runtime.report());
    ipcMain.handle('runtime:repair', () => runRepair());

    ipcMain.handle('settings:get', () => settings.all());
    ipcMain.handle('settings:save', (_event, patch) => ({ ok: true, data: applySettingsPatch(patch || {}) }));

    ipcMain.handle('logs:tail', (_event, { channel = 'app', limit = 200 } = {}) => {
        const logger = new Logger({ channel: 'app', console: false });
        return { channel, lines: logger.readChannel(channel, Math.min(1000, Number(limit) || 200)) };
    });

    ipcMain.handle('profiles:list', () => {
        const dir = paths.profiles();
        try {
            return fs.readdirSync(dir, { withFileTypes: true })
                .filter((entry) => entry.isDirectory())
                .map((entry) => ({
                    profile: entry.name,
                    modifiedAt: fs.statSync(path.join(dir, entry.name)).mtime.toISOString(),
                }));
        } catch {
            return [];
        }
    });

    ipcMain.handle('notifications:list', (_event, { unreadOnly = false } = {}) => notifier.list({ unreadOnly }));
    ipcMain.handle('notifications:read', () => {
        notifier.markAllRead();
        return { ok: true };
    });

    ipcMain.handle('workspace:enrol', async (_event, payload = {}) => {
        const { serverUrl, email, password, name } = payload;
        if (!serverUrl || !email || !password) {
            return { ok: false, error: 'Enter your workspace address, email and password.' };
        }
        try {
            if (!worker) {
                worker = new Worker();
            }
            const response = await worker.enrol({ serverUrl, email, password, name });
            settings.set('server.baseUrl', serverUrl.replace(/\/+$/, ''));
            credentials.saveWorkerCredentials({
                token: response.token,
                workerId: String(response.worker.id),
                installationId: worker.installationId,
                serverUrl: serverUrl.replace(/\/+$/, ''),
            });

            // The password is used once, in memory, to obtain a worker token.
            // It is never written to disk (prompt §5).
            const status = await startWorker();
            return { ok: true, worker: response.worker, status };
        } catch (error) {
            log.warn('Enrolment failed', { error: String(error) });
            return { ok: false, error: error.message };
        }
    });

    ipcMain.handle('workspace:signout', async () => {
        if (worker) {
            await worker.stop({ drain: false });
            worker = null;
        }
        credentials.clear();
        settings.set('server.baseUrl', '');
        return { ok: true };
    });

    ipcMain.handle('workspace:open-dashboard', () => openWorkspaceDashboard());

    ipcMain.handle('browser:focus', () => trayController.focusBrowser());
    ipcMain.handle('facebook:connect', async (_event, { accountId }) => {
        if (!worker) {
            return { ok: false, error: 'The worker is not running.' };
        }

        const account = { id: accountId, profile_ref: `acct_${accountId}` };
        const session = await worker.browsers.acquire(account);
        const sessionManager = require('../browser/session-manager');

        try {
            await session.page.bringToFront().catch(() => {});
            const result = await sessionManager.openSignIn(session, {
                onProgress: (info) => windowController?.send('worker:challenge', info),
            });

            if (result.state === 'CONNECTED') {
                const pages = await sessionManager.discoverPages(session);
                notifier.show({
                    title: 'Facebook account connected',
                    body: pages.pages.length > 0
                        ? `${pages.pages.length} Page(s) were found and are being synced.`
                        : 'Signed in. No Pages were found for this account yet.',
                });
                return { ok: true, state: result.state, pages: pages.pages.length };
            }

            return { ok: false, error: result.message || 'Sign-in was not completed.', state: result.state };
        } finally {
            await worker.browsers.release(session);
        }
    });

    ipcMain.handle('updates:check', () => checkForUpdates({ manual: true }));
    ipcMain.handle('updates:install', () => updater.installNow());

    ipcMain.handle('app:open-logs', () => shell.openPath(paths.logs()));
    ipcMain.handle('app:open-data', () => shell.openPath(paths.dataRoot()));
    ipcMain.handle('app:quit', () => shutdown({ quitApp: true }));
}

async function shutdown({ quitApp = false } = {}) {
    quitting = true;
    log.info('Shutting down LinkEasy Publisher…');

    try {
        if (worker) {
            await worker.stop({ drain: true });
        }
    } catch (error) {
        log.warn('The worker did not stop cleanly.', { error: String(error) });
    }

    try {
        await localApi?.stop();
    } catch {
        /* ignore */
    }

    trayController?.destroy();

    if (quitApp) {
        windowController?.destroy();
        app.quit();
    }
}
