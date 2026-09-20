'use strict';

/**
 * Preload bridge.
 *
 * The renderer (the app's own local dashboard) gets exactly these functions and
 * nothing else: no filesystem, no shell, no Node. Credentials are only ever sent
 * *into* the main process, never read back out (prompt §30, §52).
 */

const { contextBridge, ipcRenderer } = require('electron');

const listeners = {};

function subscribe(channel, handler) {
    if (!listeners[channel]) {
        listeners[channel] = [];
        ipcRenderer.on(channel, (_event, payload) => {
            listeners[channel].forEach((fn) => {
                try {
                    fn(payload);
                } catch {
                    /* a broken listener must not break the app */
                }
            });
        });
    }
    listeners[channel].push(handler);
    return () => {
        listeners[channel] = listeners[channel].filter((fn) => fn !== handler);
    };
}

contextBridge.exposeInMainWorld('linkeasy', {
    version: () => ipcRenderer.invoke('app:version'),

    /** Status, pause/resume, runtime and settings — all read-only or explicit. */
    getStatus: () => ipcRenderer.invoke('worker:status'),
    pause: () => ipcRenderer.invoke('worker:pause'),
    resume: () => ipcRenderer.invoke('worker:resume'),
    heartbeatNow: () => ipcRenderer.invoke('worker:heartbeat'),

    getRuntime: () => ipcRenderer.invoke('runtime:report'),
    repairRuntime: () => ipcRenderer.invoke('runtime:repair'),

    getSettings: () => ipcRenderer.invoke('settings:get'),
    saveSettings: (patch) => ipcRenderer.invoke('settings:save', patch),

    getLogs: (channel, limit) => ipcRenderer.invoke('logs:tail', { channel, limit }),
    getProfiles: () => ipcRenderer.invoke('profiles:list'),

    getNotifications: (unreadOnly) => ipcRenderer.invoke('notifications:list', { unreadOnly }),
    markNotificationsRead: () => ipcRenderer.invoke('notifications:read'),

    /** Enrolment: password goes one way, into the main process, and is not stored. */
    enrol: (payload) => ipcRenderer.invoke('workspace:enrol', payload),
    signOut: () => ipcRenderer.invoke('workspace:signout'),
    openWorkspaceDashboard: () => ipcRenderer.invoke('workspace:open-dashboard'),

    openBrowserWindow: () => ipcRenderer.invoke('browser:focus'),
    connectFacebookAccount: (accountId) => ipcRenderer.invoke('facebook:connect', { accountId }),

    checkForUpdates: () => ipcRenderer.invoke('updates:check'),
    installUpdate: () => ipcRenderer.invoke('updates:install'),
    openLogsFolder: () => ipcRenderer.invoke('app:open-logs'),
    openDataFolder: () => ipcRenderer.invoke('app:open-data'),
    quit: () => ipcRenderer.invoke('app:quit'),

    on: {
        status: (handler) => subscribe('worker:state', handler),
        progress: (handler) => subscribe('worker:job-progress', handler),
        job: (handler) => subscribe('worker:job', handler),
        notification: (handler) => subscribe('notification', handler),
        update: (handler) => subscribe('update:status', handler),
        challenge: (handler) => subscribe('worker:challenge', handler),
        navigate: (handler) => subscribe('navigate', handler),
        runtime: (handler) => subscribe('runtime:report', handler),
    },
});
