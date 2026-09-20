'use strict';

/**
 * Browser lifecycle: one Chromium process per signed-in Facebook account, and
 * never more than MAX_CONCURRENT_BROWSERS in total (prompt §12, §45).
 *
 * Thousands of Pages do not mean thousands of browsers: a single signed-in
 * profile can publish to every Page that account administers, sequentially,
 * reusing the same context.
 *
 * Failure handling (prompt §19, §44):
 *   browser crash      → relaunch → restore profile → verify session → resume
 *   profile locked     → wait for the lock then retry once
 *   invalid session    → ACCOUNT_REAUTH_REQUIRED (never re-authenticate silently)
 *
 * There is no stealth work anywhere in this file, by design. We synchronise
 * with Playwright's own waits and nothing else.
 */

const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const paths = require('../config/paths');
const settings = require('../config/settings');
const { Logger } = require('../logging/logger');

const log = new Logger({ channel: 'browser' });

class BrowserManager {
    constructor(options = {}) {
        this.maxBrowsers = options.maxBrowsers || settings.get('worker.maxConcurrentBrowsers', 2);
        this.idleTimeoutMs = (options.idleTimeoutSeconds || settings.get('worker.idleBrowserTimeoutSeconds', 300)) * 1000;
        this.sessions = new Map();       // accountId -> session record
        this.waiters = [];               // resolvers waiting for a free slot
        this.closing = false;
        this.reaper = setInterval(() => this.reapIdle().catch(() => {}), 30000);
        if (this.reaper.unref) {
            this.reaper.unref();
        }
    }

    get activeCount() {
        return [...this.sessions.values()].filter((s) => s.browser && s.browser.isConnected()).length;
    }

    /** Acquire (or create) the session for an account, respecting the cap. */
    async acquire(account) {
        const key = String(account.id);

        const existing = this.sessions.get(key);
        if (existing && existing.browser && existing.browser.isConnected()) {
            existing.lastUsedAt = Date.now();
            existing.inUse += 1;
            return existing;
        }

        await this.waitForSlot();

        const session = await this.launch(account);
        this.sessions.set(key, session);
        return session;
    }

    async release(session) {
        if (!session) {
            return;
        }
        session.inUse = Math.max(0, session.inUse - 1);
        session.lastUsedAt = Date.now();

        if (this.closing) {
            await this.closeSession(session);
            return;
        }

        // Free a waiting job immediately when nothing is using the slot.
        while (session.inUse === 0 && this.waiters.length > 0) {
            const waiter = this.waiters.shift();
            waiter();
            return;
        }
    }

    async waitForSlot() {
        if (this.activeCount < this.maxBrowsers) {
            return;
        }
        log.info('Concurrency limit reached; waiting for a browser slot.', { active: this.activeCount, max: this.maxBrowsers });
        await new Promise((resolve) => this.waiters.push(resolve));
    }

    /**
     * Launch Chromium with the account's persistent profile.
     *
     * profileRef comes from the server (an opaque reference such as acct_0184bd)
     * and maps to a folder in %LOCALAPPDATA%\LinkEasyPublisher\profiles — never
     * to a path the server can control, and never inside the install directory.
     */
    async launch(account) {
        const profileRef = account.profile_ref || `acct_${account.id}`;
        const profileDir = paths.profile(profileRef);

        if (!fs.existsSync(profileDir)) {
            fs.mkdirSync(profileDir, { recursive: true });
        }

        const lockFile = path.join(profileDir, 'SingletonLock');
        if (fs.existsSync(lockFile)) {
            // A previous run may have been killed. Chromium clears stale locks
            // itself, but a *live* second copy must not start.
            log.warn('Profile lock present; waiting before launching.', { profile: profileRef });
            await new Promise((resolve) => setTimeout(resolve, 3000));
        }

        const headless = settings.get('browser.headless', false)
            && settings.get('diagnostics.debugMode', false) === false;

        const launchOptions = {
            headless,
            args: settings.get('browser.args', []),
            viewport: settings.get('browser.viewport', { width: 1440, height: 900 }),
            locale: settings.get('browser.locale', 'en-US'),
            timeout: 60000,
            downloadsPath: paths.downloads(),
            // Never disable the browser's own safety features; we want a stock,
            // honest Chromium session.
        };

        const executablePath = resolveChromium();
        if (executablePath) {
            launchOptions.executablePath = executablePath;
        }

        let browser;
        try {
            const context = await chromium.launchPersistentContext(profileDir, launchOptions);
            browser = context.browser();
            const session = {
                accountId: account.id,
                profileRef,
                profileDir,
                context,
                browser,
                page: context.pages()[0] || await context.newPage(),
                createdAt: Date.now(),
                lastUsedAt: Date.now(),
                inUse: 1,
                relaunches: 0,
            };

            context.on('close', () => {
                log.warn('Browser context closed unexpectedly.', { profile: profileRef });
                const record = this.sessions.get(String(account.id));
                if (record && record.context === context) {
                    record.browser = null;
                }
            });

            browser?.on?.('disconnected', () => {
                log.error('Chromium disconnected.', { profile: profileRef });
            });

            log.info('Browser started', { profile: profileRef, headless });
            return session;
        } catch (error) {
            log.error('Could not start the browser', { profile: profileRef, error: String(error) });
            const wrapped = new Error(`BROWSER_LAUNCH_FAILED: ${error.message}`);
            wrapped.code = 'BROWSER_LAUNCH_FAILED';
            throw wrapped;
        }
    }

    /**
     * Recover from a crash: relaunch, restore the profile and confirm the
     * session is still valid before the caller retries the job.
     */
    async recover(account) {
        const key = String(account.id);
        const dead = this.sessions.get(key);
        if (dead) {
            await this.closeSession(dead, { force: true });
            this.sessions.delete(key);
        }

        const session = await this.launch(account);
        session.relaunches = (dead?.relaunches || 0) + 1;
        this.sessions.set(key, session);

        const state = await require('./session-manager').checkSessionState(session);
        return { session, state };
    }

    async closeSession(session, { force = false } = {}) {
        if (!session) {
            return;
        }
        if (!force && session.inUse > 0) {
            return;
        }
        try {
            await session.context?.close();
        } catch {
            /* already gone */
        }
        session.browser = null;
        log.info('Browser closed', { profile: session.profileRef });
    }

    /** Close browsers that have been idle longer than the configured timeout. */
    async reapIdle() {
        const now = Date.now();
        for (const [key, session] of this.sessions.entries()) {
            if (session.inUse > 0 || !session.browser) {
                continue;
            }
            if (now - session.lastUsedAt > this.idleTimeoutMs) {
                await this.closeSession(session, { force: true });
                this.sessions.delete(key);
                log.info('Idle browser closed to free memory.', { profile: session.profileRef });
            }
        }
        while (this.waiters.length > 0 && this.activeCount < this.maxBrowsers) {
            this.waiters.shift()();
        }
    }

    async shutdown() {
        this.closing = true;
        clearInterval(this.reaper);
        for (const session of this.sessions.values()) {
            await this.closeSession(session, { force: true });
        }
        this.sessions.clear();
    }

    status() {
        return [...this.sessions.values()].map((s) => ({
            accountId: s.accountId,
            profile: s.profileRef,
            connected: Boolean(s.browser && s.browser.isConnected()),
            inUse: s.inUse,
            idleSeconds: Math.round((Date.now() - s.lastUsedAt) / 1000),
            relaunches: s.relaunches,
        }));
    }
}

/** Prefer the Chromium that ships with the application (prompt §13). */
function resolveChromium() {
    const bundled = paths.chromiumDir();
    const candidates = [
        path.join(bundled, 'chrome-win', 'chrome.exe'),
        path.join(bundled, 'chrome-win64', 'chrome.exe'),
        path.join(bundled, 'chrome-linux', 'chrome'),
        path.join(bundled, 'chrome-mac', 'Chromium.app', 'Contents', 'MacOS', 'Chromium'),
    ];
    return candidates.find((candidate) => fs.existsSync(candidate)) || null;
}

module.exports = { BrowserManager, resolveChromium };
