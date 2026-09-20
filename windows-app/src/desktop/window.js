'use strict';

/**
 * The application window.
 *
 * A normal window (not a browser tab), with a strict Content-Security-Policy and
 * node integration disabled: the renderer can only talk to the local API through
 * the preload bridge (prompt §30, §76).
 */

const path = require('path');
const { BrowserWindow, shell, session } = require('electron');
const { Logger } = require('../logging/logger');

const log = new Logger({ channel: 'app' });

class WindowController {
    constructor() {
        this.window = null;
        this.pendingRoute = null;
    }

    create({ preload, localApi }) {
        this.window = new BrowserWindow({
            width: 1180,
            height: 780,
            minWidth: 900,
            minHeight: 620,
            show: false,
            title: 'LinkEasy Publisher',
            backgroundColor: '#0f172a',
            autoHideMenuBar: true,
            icon: path.join(__dirname, '..', '..', 'assets', 'icon.ico'),
            webPreferences: {
                preload,
                contextIsolation: true,
                nodeIntegration: false,
                sandbox: false,
                spellcheck: false,
                webSecurity: true,
                allowRunningInsecureContent: false,
            },
        });

        // Lock the renderer down: it only ever loads from the loopback API.
        const origin = `http://127.0.0.1:${localApi.port}`;
        session.defaultSession.webRequest.onHeadersReceived((details, callback) => {
            callback({
                responseHeaders: {
                    ...details.responseHeaders,
                    'Content-Security-Policy': [
                        "default-src 'none'; "
                        + `script-src ${origin}; style-src ${origin} 'unsafe-inline'; `
                        + `img-src ${origin} data:; font-src ${origin}; connect-src ${origin}; `
                        + "form-action 'none'; frame-ancestors 'none'; base-uri 'none'",
                    ],
                },
            });
        });

        this.window.loadURL(`${origin}/index.html`);
        this.window.once('ready-to-show', () => this.window.show());

        this.window.webContents.setWindowOpenHandler(({ url }) => {
            // External links (e.g. the workspace dashboard) open in the default browser.
            if (/^https:\/\//.test(url)) {
                shell.openExternal(url).catch(() => {});
            }
            return { action: 'deny' };
        });

        this.window.webContents.on('will-navigate', (event, url) => {
            if (!url.startsWith(`http://127.0.0.1:${localApi.port}`)) {
                event.preventDefault();
                log.warn('Blocked navigation away from the local dashboard.', { url });
            }
        });

        // Closing hides to the tray; the worker keeps publishing.
        this.window.on('close', (event) => {
            if (!this.quitting) {
                event.preventDefault();
                this.window.hide();
            }
        });

        this.window.webContents.on('crashed', () => {
            log.error('The application window crashed; reopening.');
            this.window.reload();
        });

        return this.window;
    }

    show(route) {
        if (!this.window) {
            return;
        }
        this.pendingRoute = route || null;
        this.window.show();
        this.window.focus();
        if (route) {
            this.window.webContents.send('navigate', route);
        }
    }

    toggle() {
        if (!this.window) {
            return;
        }
        if (this.window.isVisible()) {
            this.window.hide();
        } else {
            this.show();
        }
    }

    send(channel, payload) {
        if (this.window && !this.window.isDestroyed()) {
            this.window.webContents.send(channel, payload);
        }
    }

    destroy() {
        this.quitting = true;
        if (this.window && !this.window.isDestroyed()) {
            this.window.destroy();
        }
        this.window = null;
    }
}

module.exports = { WindowController };
