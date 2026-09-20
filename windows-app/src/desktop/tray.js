'use strict';

/**
 * System tray presence.
 *
 * The tray is the app's main surface: it shows what the worker is doing, and it
 * is where an operator pauses, resumes, reconnects an account, checks for
 * updates, opens the local dashboard or quits (prompt §28, §70).
 *
 * There are no hidden modes: quitting from the tray is the only way the process
 * exits, and it always stops the worker and closes browsers first.
 */

const path = require('path');
const { Tray, Menu, nativeImage, app, shell } = require('electron');
const { Logger } = require('../logging/logger');

const log = new Logger({ channel: 'app' });

const ICONS = {
    online: 'tray-online.png',
    busy: 'tray-busy.png',
    paused: 'tray-paused.png',
    offline: 'tray-offline.png',
    error: 'tray-error.png',
};

class TrayController {
    constructor(deps) {
        this.window = deps.window;
        this.worker = deps.worker;
        this.notifier = deps.notifier;
        this.onQuit = deps.onQuit || (() => {});
        this.onReconnect = deps.onReconnect || (() => {});
        this.onRepair = deps.onRepair || (() => {});
        this.onCheckUpdates = deps.onCheckUpdates || (() => {});
        this.onOpenDashboard = deps.onOpenDashboard || (() => {});
        this.onOpenLogs = deps.onOpenLogs || (() => {});
        this.tray = null;
        this.lastState = null;
    }

    create() {
        const icon = this.iconFor(this.worker ? this.worker.state : 'OFFLINE');
        this.tray = new Tray(icon);
        this.tray.setToolTip('LinkEasy Publisher');
        this.tray.on('click', () => this.window.toggle());
        this.refresh();

        if (this.worker) {
            this.worker.on('state', () => this.refresh());
            this.worker.on('job:done', () => this.refresh());
            this.worker.on('paused', () => this.refresh());
            this.worker.on('resumed', () => this.refresh());
        }

        // Refresh the pause/status items periodically even without events, so a
        // stale menu can never mislead the operator.
        setInterval(() => this.refresh(), 15000).unref?.();
        return this.tray;
    }

    iconFor(state) {
        const name = {
            ONLINE: ICONS.online,
            BUSY: ICONS.busy,
            PAUSED: ICONS.paused,
            DEGRADED: ICONS.offline,
            OFFLINE: ICONS.offline,
            STARTING: ICONS.busy,
            UPDATING: ICONS.busy,
            ERROR: ICONS.error,
        }[state] || ICONS.offline;

        const image = nativeImage.createFromPath(path.join(__dirname, '..', '..', 'assets', name));
        return image.isEmpty() ? nativeImage.createEmpty() : image;
    }

    refresh() {
        if (!this.tray) {
            return;
        }

        const status = this.worker ? this.worker.status() : { state: 'OFFLINE', paused: false, activeJobs: [] };
        this.lastState = status.state;
        this.tray.setImage(this.iconFor(status.state));

        const activeLine = status.activeJobs.length > 0
            ? status.activeJobs.map((job) => `${job.page || 'Account'}: ${job.type} (${job.seconds}s)`).join('\n')
            : 'Idle';

        this.tray.setToolTip(`LinkEasy Publisher — ${status.state}\n${activeLine}`);
        this.tray.setContextMenu(Menu.buildFromTemplate([
            { label: `Status: ${status.state}`, enabled: false },
            { label: status.online ? 'Connected to your workspace' : 'Offline — will retry', enabled: false },
            { label: `Active jobs: ${status.activeJobs.length}`, enabled: false },
            { type: 'separator' },
            { label: 'Open LinkEasy', click: () => this.window.show() },
            { label: 'Local dashboard', click: () => this.onOpenDashboard() },
            { type: 'separator' },
            status.paused
                ? { label: 'Resume publishing', click: () => this.worker.resume() }
                : { label: 'Pause publishing', click: () => this.pauseWorker() },
            { label: 'Open the browser window', click: () => this.focusBrowser() },
            { label: 'Reconnect a Facebook account…', click: () => this.onReconnect() },
            { type: 'separator' },
            { label: 'Check for updates', click: () => this.onCheckUpdates() },
            { label: 'Verify / repair components…', click: () => this.onRepair() },
            { label: 'Open logs folder', click: () => this.onOpenLogs() },
            { label: 'Settings…', click: () => this.window.show('settings') },
            { type: 'separator' },
            { label: `Version ${app.getVersion()}`, enabled: false },
            { label: 'Quit LinkEasy Publisher', click: () => this.onQuit() },
        ]));

        if (status.paused && this.lastState !== 'PAUSED_NOTIFIED') {
            this.notifier.show({
                title: 'Publishing paused',
                body: 'LinkEasy is waiting for you. Open the app to see what is needed.',
                urgent: true,
            });
            this.lastState = 'PAUSED_NOTIFIED';
        }
    }

    pauseWorker() {
        if (!this.worker) {
            return;
        }
        this.worker.paused = true;
        this.worker.pausedReason = { code: 'OPERATOR_PAUSED' };
        this.worker.setState('PAUSED');
        log.info('Worker paused from the tray.');
    }

    async focusBrowser() {
        const sessions = this.worker ? this.worker.browsers.status() : [];
        const live = sessions.find((session) => session.connected);

        if (!live) {
            this.notifier.show({
                title: 'No browser is open',
                body: 'A browser window opens automatically when a job needs one.',
            });
            return;
        }

        const session = this.worker.browsers.sessions.get(String(live.accountId));
        try {
            await session?.page?.bringToFront();
        } catch (error) {
            log.warn('Could not bring the browser to the front.', { error: String(error) });
        }
    }

    destroy() {
        if (this.tray) {
            this.tray.destroy();
            this.tray = null;
        }
    }
}

module.exports = { TrayController };
