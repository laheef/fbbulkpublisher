'use strict';

/**
 * Local defaults. Anything the server sends in /worker/config overrides these
 * at runtime, so the dashboard remains the source of truth for behaviour that
 * affects publishing.
 */

module.exports = {
    app: {
        name: 'LinkEasy Publisher',
        version: '1.0.0',
        workerVersion: '1.0.0',
        userAgentSuffix: '',
    },

    server: {
        // Filled in during the "Connect your workspace" step.
        baseUrl: '',
        workspaceEmail: '',
        verifyTls: true,
        heartbeatSeconds: 20,
        pollSeconds: 15,
        requestTimeoutMs: 30000,
        maxBackoffSeconds: 300,
    },

    worker: {
        maxConcurrentJobs: 2,
        maxConcurrentBrowsers: 2,
        maxJobsPerWorker: 10,
        idleBrowserTimeoutSeconds: 300,
        jobTimeoutSeconds: 1800,
        startWithWindows: true,
        minimizeToTray: true,
        autoStartWorker: true,
        paused: false,
    },

    browser: {
        headless: false,              // the operator may need to see/complete a challenge
        channel: 'chromium',
        slowMoMs: 0,
        defaultTimeoutMs: 30000,
        navigationTimeoutMs: 60000,
        viewport: { width: 1440, height: 900 },
        // The session must look like an ordinary desktop browser, because it
        // *is* one: no spoofing, no stealth plugins, no fingerprint tampering.
        locale: 'en-US',
        args: [
            '--disable-blink-features=AutomationControlled=0',
            '--no-first-run',
            '--no-default-browser-check',
            '--disable-features=Translate,MediaRouter',
        ],
    },

    media: {
        strategy: 'SERVER',
        cleanupAfterPublish: true,
        maxLocalCacheBytes: 5 * 1024 * 1024 * 1024,
        keepFailedMediaHours: 72,
        thumbnailSeconds: 1,
    },

    diagnostics: {
        traceMode: 'failures_only',   // off | failures_only | all
        captureScreenshots: true,
        debugMode: false,
        verboseLogs: false,
        traceRetentionDays: 14,
        screenshotRetentionDays: 30,
        logRetentionDays: 30,
    },

    updater: {
        enabled: true,
        feedUrl: 'https://updates.example.com/linkeasy-publisher/',
        channel: 'stable',
        requireSignature: true,
        checkIntervalHours: 6,
    },

    localApi: {
        // A random high port is chosen at startup; the API binds to 127.0.0.1 only.
        host: '127.0.0.1',
        port: 0,
        requireToken: true,
    },
};
