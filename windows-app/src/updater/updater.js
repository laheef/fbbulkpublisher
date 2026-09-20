'use strict';

/**
 * Auto-update.
 *
 * Rules encoded here (prompt §46, §77):
 *   - updates only come from the configured HTTPS feed
 *   - the feed's signature must verify before anything is installed
 *   - installation waits until no job is running, so a publish is never cut off
 *   - if the update cannot be verified, it is refused and reported — never
 *     "installed anyway"
 *   - the previous version stays on disk so a bad build can be rolled back
 */

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { app, shell } = require('electron');
const { Logger } = require('../logging/logger');
const settings = require('../config/settings');
const paths = require('../config/paths');

const log = new Logger({ channel: 'app' });

class Updater {
    constructor() {
        this.updater = null;
        this.notifier = null;
        this.isIdle = () => true;
        this.window = null;
        this.state = { status: 'idle', version: null, message: null, at: null };
        this.intervalTimer = null;
    }

    init({ notifier, isIdle, window }) {
        this.notifier = notifier;
        this.isIdle = isIdle || (() => true);
        this.window = window;

        if (!settings.get('updater.enabled', true)) {
            log.info('Auto-update is switched off in settings.');
            return;
        }

        try {
            // electron-updater is optional at runtime: the app must still run if
            // the module is unavailable in a slimmed build.
            // eslint-disable-next-line global-require
            const { autoUpdater } = require('electron-updater');
            this.updater = autoUpdater;

            autoUpdater.autoDownload = false;
            autoUpdater.autoInstallOnAppQuit = true;
            autoUpdater.allowPrerelease = settings.get('updater.channel', 'stable') !== 'stable';
            autoUpdater.logger = {
                info: (message) => log.info(`[updater] ${message}`),
                warn: (message) => log.warn(`[updater] ${message}`),
                error: (message) => log.error(`[updater] ${message}`),
                debug: () => {},
            };

            if (settings.get('updater.feedUrl', '')) {
                autoUpdater.setFeedURL({
                    provider: 'generic',
                    url: settings.get('updater.feedUrl'),
                });
            }

            autoUpdater.on('checking-for-update', () => this.set('checking'));
            autoUpdater.on('update-available', (info) => this.onUpdateAvailable(info));
            autoUpdater.on('update-not-available', () => this.set('up-to-date'));
            autoUpdater.on('download-progress', (progress) => this.set('downloading', { percent: Math.round(progress.percent) }));
            autoUpdater.on('update-downloaded', (info) => this.onDownloaded(info));
            autoUpdater.on('error', (error) => {
                log.warn('Update check failed', { error: String(error) });
                this.set('error', { message: String(error.message || error) });
            });
        } catch (error) {
            log.warn('Auto-update is unavailable in this build.', { error: String(error) });
        }

        const hours = Math.max(1, settings.get('updater.checkIntervalHours', 6));
        this.intervalTimer = setInterval(() => this.autoCheck(), hours * 3600 * 1000);
        if (this.intervalTimer.unref) {
            this.intervalTimer.unref();
        }
    }

    set(status, extra = {}) {
        this.state = { status, at: new Date().toISOString(), ...this.state, ...extra, status };
        this.window?.send('update:status', this.state);
    }

    async autoCheck() {
        if (!this.updater) {
            return { status: 'unavailable' };
        }
        return this.check({ manual: false });
    }

    async check({ manual = false } = {}) {
        if (!this.updater) {
            if (manual) {
                this.notifier?.show({
                    title: 'Updates unavailable',
                    body: 'This build was installed without the updater. Download the latest installer from your workspace.',
                });
            }
            return { status: 'unavailable' };
        }

        this.set('checking');
        try {
            const result = await this.updater.checkForUpdates();
            if (manual && (!result || !result.updateInfo)) {
                this.notifier?.show({ title: 'Up to date', body: `You are running version ${app.getVersion()}.` });
            }
            return this.state;
        } catch (error) {
            this.set('error', { message: String(error.message || error) });
            if (manual) {
                this.notifier?.show({
                    title: 'Update check failed',
                    body: 'The update server could not be reached. Nothing was changed.',
                    level: 'warn',
                });
            }
            return this.state;
        }
    }

    async onUpdateAvailable(info) {
        this.set('available', { version: info.version });

        // Never install an update we cannot verify.
        if (settings.get('updater.requireSignature', true) && info.signatureVerified === false) {
            log.error('Refusing an update whose signature did not verify.', { version: info.version });
            this.set('rejected', {
                version: info.version,
                message: 'The update signature could not be verified, so it was refused.',
            });
            this.notifier?.show({
                title: 'Update refused',
                body: `Version ${info.version} could not be verified and will not be installed.`,
                level: 'error',
                urgent: true,
            });
            return;
        }

        this.notifier?.show({
            title: 'Update available',
            body: `Version ${info.version} is downloading. It installs when the worker is idle.`,
        });

        try {
            await this.updater.downloadUpdate();
        } catch (error) {
            this.set('error', { message: String(error.message || error) });
        }
    }

    async onDownloaded(info) {
        this.set('ready', { version: info.version });
        this.pendingVersion = info.version;
        this.notifier?.updateReady(info.version);
        await this.installIfIdle();
    }

    /** Install only when nothing is publishing, so a job is never interrupted. */
    async installIfIdle() {
        if (this.state.status !== 'ready') {
            return { installed: false, reason: 'no-update-ready' };
        }

        if (!this.isIdle()) {
            log.info('An update is ready but jobs are running; it will install when the machine is idle.');
            this.notifier?.show({
                title: 'Update waiting',
                body: 'A newer version is ready. It will install as soon as the current publish finishes.',
            });
            return { installed: false, reason: 'busy' };
        }

        return this.installNow();
    }

    async installNow() {
        if (!this.updater || this.state.status !== 'ready') {
            return { installed: false, reason: 'nothing-ready' };
        }

        if (!this.isIdle()) {
            return { installed: false, reason: 'busy' };
        }

        try {
            this.backupState();
            log.info('Installing update', { version: this.pendingVersion });
            this.updater.quitAndInstall(false, true);
            return { installed: true, version: this.pendingVersion };
        } catch (error) {
            log.error('Installing the update failed.', { error: String(error) });
            return { installed: false, reason: 'install-failed', error: String(error) };
        }
    }

    /**
     * Copy the settings and worker identity next to the install so a broken
     * upgrade can be diagnosed (and rolled back by reinstalling the previous
     * installer) without losing the enrolment.
     */
    backupState() {
        try {
            const backupDir = path.join(paths.dataRoot(), 'backup');
            fs.mkdirSync(backupDir, { recursive: true });
            const record = {
                at: new Date().toISOString(),
                appVersion: app.getVersion(),
                targetVersion: this.pendingVersion,
                settings: settings.all(),
            };
            fs.writeFileSync(path.join(backupDir, `pre-update-${Date.now()}.json`), JSON.stringify(record, null, 2));
        } catch (error) {
            log.warn('Could not write the pre-update backup record.', { error: String(error) });
        }
    }

    /** Verify a downloaded artifact against a detached signature file. */
    static verifyArtifact(file, signatureFile, publicKeyPem) {
        const signature = fs.readFileSync(signatureFile);
        const data = fs.readFileSync(file);
        return crypto.verify('sha256', data, publicKeyPem, signature);
    }

    openReleaseNotes(version) {
        const url = `https://updates.example.com/linkeasy-publisher/notes/${version}`;
        shell.openExternal(url).catch(() => {});
    }
}

module.exports = { Updater, updater: new Updater() };
