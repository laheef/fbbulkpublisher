'use strict';

/**
 * Account-level jobs: VERIFY_SESSION and DETECT_PAGES.
 *
 * Both are read-only. DETECT_PAGES walks the account's Page list and hands it to
 * the server; the server decides what is new, renamed or missing. The worker
 * never deletes anything on its own (prompt §26, §61).
 */

const sessionManager = require('../browser/session-manager');
const challenges = require('../browser/challenge-detector');
const { Logger } = require('../logging/logger');

const log = new Logger({ channel: 'worker' });

async function verifySession(ctx) {
    const { session, progress } = ctx;
    await progress('verify_session', 20, 'Checking the Facebook session');

    const state = await sessionManager.checkSessionState(session);

    switch (state) {
        case 'CONNECTED':
            return { ok: true, verified: true, state, message: 'The signed-in session is healthy.' };
        case 'AUTH_REQUIRED':
            return {
                ok: false,
                userAction: true,
                code: 'ACCOUNT_REAUTH_REQUIRED',
                state,
                message: 'The Facebook session has expired. Reconnect the account from the tray app — you will sign in yourself.',
            };
        case 'CHALLENGE_REQUIRED':
            return {
                ok: false,
                userAction: true,
                code: 'SECURITY_CHALLENGE',
                state,
                message: 'Facebook is asking for verification before this session can be used.',
            };
        case 'ERROR':
            return { ok: false, code: 'ACCOUNT_DISABLED', state, message: 'Facebook is showing a restriction on this account.' };
        default:
            return {
                ok: false,
                code: 'UNKNOWN_TRANSIENT',
                state,
                message: 'The session state could not be determined; it will be checked again on the next run.',
            };
    }
}

async function detectPages(ctx) {
    const { session, job, progress } = ctx;
    await progress('detect_pages', 25, 'Reading the Page list from Facebook');

    const result = await sessionManager.discoverPages(session, { limit: 300 });

    if (!result.ok) {
        return { ok: false, code: result.code, message: result.detail || 'The Page list could not be read.' };
    }

    log.info('Page list read', { pages: result.pages.length, accountId: job.account_id });

    // Reports the list; the server reconciles it.
    return {
        ok: true,
        pages: result.pages.map((page) => ({
            external_id: page.external_id,
            name: page.name,
            url: page.url,
            avatar_url: page.avatar_url,
        })),
    };
}

/** Called between jobs to keep the account's state fresh. */
async function refreshAccountStatus(session, account) {
    const state = await sessionManager.checkSessionState(session);
    if (state === 'CHALLENGE_REQUIRED') {
        const challenge = await challenges.detect(await sessionManager.ensurePage(session));
        return { status: 'CHALLENGE_REQUIRED', code: challenge.code, detail: challenge.detail };
    }
    return { status: state };
}

module.exports = { verifySession, detectPages, refreshAccountStatus };
