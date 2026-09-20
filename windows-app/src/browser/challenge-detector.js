'use strict';

/**
 * Challenge and security-control detection (prompt §20).
 *
 * Hard rules encoded here:
 *   - We never solve, bypass, automate or click through a CAPTCHA, checkpoint,
 *     2FA prompt or identity verification.
 *   - Detection pauses the job, raises USER_ACTION_REQUIRED on the server,
 *     notifies the operator and brings the browser window to the foreground.
 *   - The worker only continues after the operator says the check is done; if
 *     the check is still there, we do not proceed.
 */

const selectors = require('./selectors');
const { Logger } = require('../logging/logger');

const log = new Logger({ channel: 'browser' });

const CHALLENGE_TYPES = {
    CAPTCHA_DETECTED: 'captcha',
    SECURITY_CHALLENGE: 'checkpoint',
    CHECKPOINT: 'checkpoint',
    '2FA_REQUIRED': 'twoFactor',
    IDENTITY_VERIFICATION: 'identity',
};

const BLOCK_TYPES = {
    ACCOUNT_DISABLED: 'securityBlock',
    PROFILE_LOCKED: 'securityBlock',
    RATE_LIMITED: 'rateLimit',
};

/** Inspect the current page and classify what Facebook is showing. */
async function detect(page) {
    const checks = [
        ['CAPTCHA_DETECTED', 'challenge.captcha'],
        ['CHECKPOINT', 'challenge.checkpoint'],
        ['2FA_REQUIRED', 'challenge.twoFactor'],
        ['IDENTITY_VERIFICATION', 'challenge.identity'],
        ['ACCOUNT_DISABLED', 'challenge.securityBlock'],
        ['RATE_LIMITED', 'challenge.rateLimit'],
    ];

    for (const [code, path] of checks) {
        const found = await selectors.resolve(page, path, { timeoutMs: 300, visible: true });
        if (found) {
            const detail = await describe(page, path);
            log.warn(`Security control detected: ${code}`, { detail });
            return {
                detected: true,
                code,
                selectorPath: path,
                detail,
                // Only one of these is a challenge the operator completes by hand:
                captcha: code === 'CAPTCHA_DETECTED',
                blocking: code === 'ACCOUNT_DISABLED' || code === 'PROFILE_LOCKED',
            };
        }
    }

    return { detected: false, code: null, selectorPath: null, detail: null };
}

async function describe(page, path) {
    try {
        const text = await page.locator(selectors.css(path)).first().innerText({ timeout: 1000 });
        return String(text).replace(/\s+/g, ' ').slice(0, 200);
    } catch {
        return null;
    }
}

/**
 * Wait for the operator to finish a security check.
 *
 * We deliberately do not "assist" in any way: no refresh loops, no retries into
 * the challenge, no form filling. We poll only to notice when the page has
 * moved on.
 *
 * @param {object} page
 * @param {(info:object)=>void} onRequired called once when the pause begins
 * @param {(info:object)=>Promise<boolean>} isResolved asked periodically whether
 *        the operator has confirmed completion in the tray app
 */
async function waitForOperator(page, { onRequired, isResolved, timeoutMs = 30 * 60 * 1000, pollMs = 3000 } = {}) {
    const detection = await detect(page);

    if (!detection.detected) {
        return { proceeded: true, detection };
    }

    if (detection.blocking) {
        return {
            proceeded: false,
            detection,
            code: detection.code,
            message: 'Facebook has restricted this account. Sign in to Facebook in the browser profile to review the message, then reconnect the account from the dashboard.',
        };
    }

    if (typeof onRequired === 'function') {
        await onRequired({
            code: detection.code,
            detail: detection.detail,
            instruction: 'Finish the security check in the browser window that just came to the front. The queue is paused for this Page until you do.',
        });
    }

    const deadline = Date.now() + timeoutMs;

    while (Date.now() < deadline) {
        await new Promise((resolve) => setTimeout(resolve, pollMs));

        if (typeof isResolved === 'function' && await isResolved()) {
            const stillThere = await detect(page);
            if (!stillThere.detected) {
                log.info('Security check cleared by the operator.');
                return { proceeded: true, detection: stillThere, resumedByOperator: true };
            }
        }

        const fresh = await detect(page);
        if (!fresh.detected && await operatorLooksSignedIn(page)) {
            log.info('Security check no longer present; continuing.');
            return { proceeded: true, detection: fresh };
        }
    }

    return {
        proceeded: false,
        detection,
        code: detection.code || 'SECURITY_CHALLENGE',
        message: 'The security check was not completed in time. The job was left paused; nothing was published.',
    };
}

async function operatorLooksSignedIn(page) {
    const marker = await selectors.resolve(page, 'session.profileMenu', { timeoutMs: 500 });
    return Boolean(marker);
}

/** Is the page currently showing a login form or a "session expired" prompt? */
async function sessionLooksInvalid(page) {
    const loggedOut = await selectors.resolve(page, 'session.loggedOutMarker', { timeoutMs: 500 });
    if (!loggedOut) {
        return false;
    }
    const stillSignedIn = await selectors.resolve(page, 'session.profileMenu', { timeoutMs: 500 });
    return !stillSignedIn;
}

module.exports = { detect, waitForOperator, sessionLooksInvalid, CHALLENGE_TYPES, BLOCK_TYPES };
