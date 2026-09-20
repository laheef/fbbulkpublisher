'use strict';

/**
 * "Start with Windows".
 *
 * Uses the Windows registry Run entry that Electron maintains, so the operator
 * never edits a shortcut or a startup folder (prompt §31).
 *
 * In portable mode we do not register anything: a portable copy must leave no
 * trace on the machine it ran on, and the operator can add it to startup
 * themselves if they want.
 */

const { app } = require('electron');
const paths = require('../config/paths');
const { Logger } = require('../logging/logger');

const log = new Logger({ channel: 'app' });

function currentSettings() {
    try {
        return app.getLoginItemSettings();
    } catch {
        return { openAtLogin: false };
    }
}

function isEnabled() {
    if (paths.portable) {
        return false;
    }
    return Boolean(currentSettings().openAtLogin);
}

function setEnabled(enabled) {
    if (paths.portable) {
        log.info('Portable mode: the "start with Windows" setting is not registered.');
        return { supported: false, enabled: false };
    }

    if (process.platform !== 'win32' && process.platform !== 'darwin') {
        return { supported: false, enabled: false };
    }

    try {
        app.setLoginItemSettings({
            openAtLogin: Boolean(enabled),
            // Start minimised in the tray: the worker should not steal focus at logon.
            args: ['--hidden'],
            name: 'LinkEasy Publisher',
        });

        log.info('Start-with-Windows updated.', { enabled: Boolean(enabled) });
        return { supported: true, enabled: Boolean(enabled) };
    } catch (error) {
        log.warn('Could not update the start-with-Windows setting.', { error: String(error) });
        return { supported: false, enabled: false, error: error.message };
    }
}

/** Apply the stored preference at launch. */
function applyStoredPreference() {
    const desired = Boolean(require('../config/settings').get('worker.startWithWindows', true));
    const current = isEnabled();
    if (desired !== current) {
        setEnabled(desired);
    }
    return isEnabled();
}

function launchedAtStartup() {
    return process.argv.includes('--hidden') || Boolean(currentSettings().wasOpenedAtLogin);
}

module.exports = { isEnabled, setEnabled, applyStoredPreference, launchedAtStartup };
