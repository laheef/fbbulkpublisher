'use strict';

/**
 * Session lifecycle for a connected Facebook account.
 *
 * The operator signs in to Facebook inside this profile exactly once, by hand.
 * We never ask for, store, transmit or type a Facebook password — the login
 * form is for the human (prompt §5, prompt §25).
 *
 * What we do afterwards:
 *   - keep the profile on disk, encrypted at rest by Windows (DPAPI-scoped
 *     folder ACLs, per Windows user)
 *   - verify the session before using it
 *   - report ACCOUNT_REAUTH_REQUIRED when it has expired, instead of guessing
 */

const { Logger } = require('../logging/logger');
const selectors = require('../facebook/selectors');
const challenges = require('./challenge-detector');

const log = new Logger({ channel: 'browser' });

const HOME = 'https://www.facebook.com/';

/**
 * @returns {Promise<'CONNECTED'|'AUTH_REQUIRED'|'CHALLENGE_REQUIRED'|'UNKNOWN'>}
 */
async function checkSessionState(session) {
    const page = await ensurePage(session);

    try {
        await page.goto(HOME, { waitUntil: 'domcontentloaded', timeout: 60000 });
    } catch (error) {
        log.warn('Could not reach Facebook while checking the session.', { error: String(error) });
        return 'UNKNOWN';
    }

    const challenge = await challenges.detect(page);
    if (challenge.detected) {
        return challenge.blocking ? 'ERROR' : 'CHALLENGE_REQUIRED';
    }

    if (await challenges.sessionLooksInvalid(page)) {
        return 'AUTH_REQUIRED';
    }

    const marker = await selectors.resolve(page, 'session.profileMenu', { timeoutMs: 8000 });
    if (marker) {
        return 'CONNECTED';
    }

    // No login form and no profile menu: we cannot tell, so we say so rather
    // than pretend the session is healthy.
    return 'UNKNOWN';
}

async function ensurePage(session) {
    if (session.page && !session.page.isClosed()) {
        return session.page;
    }
    session.page = await session.context.newPage();
    return session.page;
}

/**
 * Interactive sign-in helper used by the tray app's "Connect / Reconnect".
 *
 * The browser is brought to the front, the operator signs in (including any 2FA
 * Facebook asks for), and we only verify the result afterwards.
 */
async function openSignIn(session, { onProgress } = {}) {
    const page = await ensurePage(session);
    await page.goto(`${HOME}login`, { waitUntil: 'domcontentloaded', timeout: 60000 });

    if (onProgress) {
        await onProgress({
            stage: 'sign_in',
            message: 'Sign in to Facebook in the window that just opened. LinkEasy never sees or stores your password.',
        });
    }

    const deadline = Date.now() + 15 * 60 * 1000;
    while (Date.now() < deadline) {
        await new Promise((resolve) => setTimeout(resolve, 3000));

        const state = await checkSessionState(session);
        if (state === 'CONNECTED') {
            return { state };
        }
        if (state === 'CHALLENGE_REQUIRED') {
            // Facebook may ask for 2FA or a checkpoint during sign-in; that is
            // the operator's step, not ours.
            const challenge = await challenges.detect(page);
            if (onProgress) {
                await onProgress({
                    stage: 'challenge',
                    message: `Facebook is asking for ${challenge.detail || 'additional verification'}. Please complete it in the browser window.`,
                    code: challenge.code,
                });
            }
        }
    }

    return { state: 'AUTH_REQUIRED', message: 'Sign-in was not completed in time.' };
}

/**
 * Discover the Pages this account manages (prompt §6, §26).
 * Read-only: we open the Page switcher, read what Facebook renders, and close it.
 */
async function discoverPages(session, { limit = 200 } = {}) {
    const page = await ensurePage(session);
    const discovered = [];

    await page.goto('https://www.facebook.com/pages/?category=your_pages', {
        waitUntil: 'domcontentloaded',
        timeout: 60000,
    });

    const challenge = await challenges.detect(page);
    if (challenge.detected) {
        return { ok: false, code: challenge.code, pages: [], detail: challenge.detail };
    }

    await page.waitForTimeout(2500);   // allow the list to render

    // Collect the rows we can actually see, scrolling a bounded number of times.
    let previousCount = -1;
    for (let scroll = 0; scroll < 25 && discovered.length < limit; scroll += 1) {
        const rows = await readPageRows(page);
        for (const row of rows) {
            if (!discovered.some((p) => p.external_id === row.external_id)) {
                discovered.push(row);
            }
        }

        if (rows.length === previousCount) {
            break;
        }
        previousCount = rows.length;

        await page.mouse.wheel(0, 2400);
        await page.waitForTimeout(1200);
    }

    if (discovered.length === 0) {
        const uiChanged = !(await selectors.resolve(page, 'pages.accountList', { timeoutMs: 3000, visible: false })
            || await selectors.resolve(page, 'pages.pageRowLink', { timeoutMs: 3000, visible: false }));

        if (uiChanged) {
            return {
                ok: false,
                code: 'FACEBOOK_UI_CHANGED',
                pages: [],
                detail: 'The Pages list could not be read — Facebook\'s interface may have changed.',
            };
        }
    }

    return { ok: true, pages: discovered };
}

async function readPageRows(page) {
    try {
        return await page.evaluate(() => {
            const out = [];
            const anchors = Array.from(document.querySelectorAll('a[href*="/profile.php?id="], a[href^="/"]'));
            for (const anchor of anchors) {
                const href = anchor.getAttribute('href') || '';
                const match = href.match(/profile\.php\?id=(\d+)/) || href.match(/^\/([A-Za-z0-9.\-_]{5,})\/?$/);
                if (!match) {
                    continue;
                }
                const externalId = match[1];
                if (!/^\d+$/.test(externalId) && /^(pages|settings|help|messages|notifications|friends|groups|watch|marketplace|gaming|login|recover)$/i.test(externalId)) {
                    continue;
                }
                const name = (anchor.textContent || '').trim().replace(/\s+/g, ' ');
                if (name.length < 2 || name.length > 120) {
                    continue;
                }
                const avatar = anchor.querySelector('img');
                out.push({
                    external_id: externalId,
                    name,
                    url: href.startsWith('http') ? href : `https://www.facebook.com${href}`,
                    avatar_url: avatar ? avatar.getAttribute('src') : null,
                });
            }
            return out;
        });
    } catch {
        return [];
    }
}

/** Switch the signed-in context to a specific Page before composing. */
async function actAsPage(page, pageRef) {
    const targets = [];
    if (pageRef.url) {
        targets.push(pageRef.url);
    }
    if (pageRef.external_id) {
        targets.push(`https://www.facebook.com/profile.php?id=${pageRef.external_id}`);
        targets.push(`https://www.facebook.com/${pageRef.external_id}`);
    }

    for (const target of targets) {
        try {
            await page.goto(target, { waitUntil: 'domcontentloaded', timeout: 60000 });
            const marker = await selectors.resolve(page, 'pages.pageLoadedMarker', { timeoutMs: 8000, visible: false });
            const challenge = await challenges.detect(page);
            if (challenge.detected) {
                return { ok: false, code: challenge.code, detail: challenge.detail };
            }
            if (marker || await selectors.resolve(page, 'composer.composerTrigger', { timeoutMs: 5000 })) {
                return { ok: true, url: page.url() };
            }
        } catch {
            /* try the next URL shape */
        }
    }

    return { ok: false, code: 'PAGE_NOT_FOUND', detail: 'That Page could not be opened while signed in as this account.' };
}

module.exports = { checkSessionState, openSignIn, discoverPages, actAsPage, ensurePage };
