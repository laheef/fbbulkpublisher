'use strict';

/**
 * Windows toast notifications.
 *
 * Desktop notifications are used for the four things an operator must not miss
 * (prompt §29, §69): a post published, a failure, a security check waiting, and
 * an update or repair that needs their attention.
 *
 * Secrets never appear in a notification body — the strings passed here are
 * already redacted by the caller and truncated for the toast.
 */

const { Notification } = require('electron');
const { Logger } = require('../logging/logger');

const log = new Logger({ channel: 'app' });
const HISTORY_LIMIT = 50;

class Notifier {
    constructor(options = {}) {
        this.supported = Notification.isSupported();
        this.history = [];
        this.onClick = options.onClick || (() => {});
        this.onAction = options.onAction || (() => {});
        this.enabled = options.enabled !== false;
        this.lastShown = new Map();
    }

    /**
     * @param {object} payload
     * @param {string} payload.title
     * @param {string} payload.body
     * @param {'info'|'warn'|'error'} [payload.level]
     * @param {boolean} [payload.urgent] keep the toast on screen longer
     * @param {string} [payload.action] label for the primary action button
     */
    show(payload) {
        const entry = {
            at: new Date().toISOString(),
            title: truncate(payload.title || 'LinkEasy Publisher', 64),
            body: truncate(payload.body || '', 200),
            level: payload.level || 'info',
            urgent: Boolean(payload.urgent),
            read: false,
        };

        this.history.unshift(entry);
        if (this.history.length > HISTORY_LIMIT) {
            this.history.length = HISTORY_LIMIT;
        }

        if (!this.enabled) {
            log.debug('Notification suppressed (notifications are off).', { title: entry.title });
            return entry;
        }

        // Collapse repeated identical toasts (a flapping job must not spam).
        const key = `${entry.title}|${entry.body}`;
        const last = this.lastShown.get(key) || 0;
        if (Date.now() - last < 30000) {
            return entry;
        }
        this.lastShown.set(key, Date.now());

        if (!this.supported) {
            log.info(`[notification] ${entry.title} — ${entry.body}`);
            return entry;
        }

        try {
            const notification = new Notification({
                title: entry.title,
                body: entry.body,
                silent: false,
                urgency: payload.level === 'error' ? 'critical' : 'normal',
                timeoutType: entry.urgent ? 'never' : 'default',
            });

            notification.on('click', () => {
                entry.read = true;
                this.onClick(entry);
            });

            if (payload.action) {
                notification.actions = [{ type: 'button', text: truncate(payload.action, 20) }];
                notification.on('action', () => this.onAction(entry));
            }

            notification.show();
        } catch (error) {
            log.warn('Could not show a Windows notification', { error: String(error) });
        }

        return entry;
    }

    published(pageName, url) {
        return this.show({
            title: 'Post published',
            body: `${pageName} — the post is live.`,
            level: 'info',
            action: url ? 'Open' : undefined,
        });
    }

    failed(pageName, code, message) {
        return this.show({
            title: 'Publishing failed',
            body: `${pageName}: ${message || code}`,
            level: 'error',
        });
    }

    challenge(pageName, message) {
        return this.show({
            title: 'Action needed',
            body: `${pageName}: ${message || 'Facebook is asking for verification. Finish it in the browser window.'}`,
            level: 'warn',
            urgent: true,
            action: 'Open browser',
        });
    }

    updateReady(version) {
        return this.show({
            title: 'Update ready',
            body: `LinkEasy Publisher ${version} will be installed when the worker is idle.`,
            level: 'info',
            action: 'Install now',
        });
    }

    runtimeProblem(summary) {
        return this.show({
            title: 'Runtime problem detected',
            body: `${summary.problems} component(s) need repair before publishing can continue.`,
            level: 'error',
            action: 'Repair now',
        });
    }

    list({ unreadOnly = false } = {}) {
        return unreadOnly ? this.history.filter((entry) => !entry.read) : this.history;
    }

    markAllRead() {
        this.history = this.history.map((entry) => ({ ...entry, read: true }));
    }

    setEnabled(enabled) {
        this.enabled = Boolean(enabled);
    }
}

function truncate(value, length) {
    const text = String(value);
    return text.length <= length ? text : `${text.slice(0, length - 1)}…`;
}

module.exports = { Notifier, truncate };
