'use strict';

/**
 * Facebook composer flows.
 *
 * Only three things happen here: open the Page, compose, and hand the outcome
 * back as a structured result. Anything Facebook does that we cannot interpret
 * ends the attempt with a specific failure code (prompt §18, §19, §21).
 *
 * Deliberately absent: random human-like delays, mouse-path simulation,
 * fingerprint spoofing, CAPTCHA handling. Where we need to wait for Facebook to
 * finish something, we wait on Playwright's own signals (prompt §24).
 */

const fs = require('fs');
const path = require('path');
const selectors = require('./selectors');
const challenges = require('../browser/challenge-detector');
const uploads = require('../browser/upload-helper');
const verification = require('../browser/verification');
const sessionManager = require('../browser/session-manager');
const { Logger } = require('../logging/logger');

const log = new Logger({ channel: 'publisher' });

/**
 * Publish one job.
 *
 * @param {object} ctx
 * @param {object} ctx.page         Playwright page bound to the account profile
 * @param {object} ctx.job          the job payload from the server
 * @param {string|null} ctx.mediaFile local path of the media, already downloaded
 * @param {function} ctx.progress   (stage, pct, message) => Promise
 * @returns {Promise<{ok:boolean, code?:string, message?:string, resultUrl?:string, verified?:boolean, userAction?:boolean}>}
 */
async function publish(ctx) {
    const { page, job, mediaFile, progress } = ctx;
    const pageRef = job.page;

    await progress('open_page', 5, `Opening ${pageRef.name}`);

    const navigation = await sessionManager.actAsPage(page, pageRef);
    if (!navigation.ok) {
        return { ok: false, code: navigation.code, message: navigation.detail };
    }

    const challenge = await challenges.detect(page);
    if (challenge.detected) {
        return {
            ok: false,
            userAction: true,
            code: challenge.code,
            message: challenge.detail || 'Facebook asked for a security verification.',
        };
    }

    await progress('open_composer', 15, 'Opening the composer');
    const opened = await openComposer(page);
    if (!opened.ok) {
        return opened;
    }

    if (mediaFile && job.media) {
        await progress('media_upload', 30, `Uploading ${path.basename(mediaFile)}`);
        const attached = await uploads.attachFile(page, mediaFile, { kind: job.media.kind });
        if (!attached.ok) {
            await closeComposer(page);
            return attached;
        }
    }

    const caption = buildCaption(job.content);
    if (caption) {
        await progress('typing_caption', 55, 'Adding the caption');
        const typed = await typeCaption(page, caption);
        if (!typed.ok) {
            await closeComposer(page);
            return typed;
        }
    } else if (!mediaFile) {
        return { ok: false, code: 'POST_EMPTY', message: 'This job has neither text nor media to publish.' };
    }

    await progress('submitting', 70, 'Publishing');
    const submitted = await submit(page, job);
    if (!submitted.ok) {
        return submitted;
    }

    await progress('verifying', 85, 'Confirming the post appeared');
    const verified = await verification.verifyPublication(page, job);

    if (verified.verified) {
        return { ok: true, resultUrl: verified.url, verified: true, evidence: verified.evidence };
    }

    return {
        ok: false,
        code: 'PUBLISH_VERIFICATION_REQUIRED',
        message: verified.detail,
        candidateUrl: verified.candidateUrl,
    };
}

async function openComposer(page) {
    const trigger = await selectors.resolve(page, 'composer.composerTrigger', { timeoutMs: 20000 });

    if (!trigger) {
        const uiOk = await selectors.resolve(page, 'pages.pageLoadedMarker', { timeoutMs: 3000, visible: false });
        return uiOk
            ? { ok: false, code: 'FACEBOOK_UI_CHANGED', message: 'The Page opened, but the composer button was not found. Facebook may have changed this interface.' }
            : { ok: false, code: 'PAGE_NOT_FOUND', message: 'The Page did not load while signed in as this account.' };
    }

    try {
        await trigger.click({ timeout: 15000 });
    } catch (error) {
        return { ok: false, code: 'FACEBOOK_UI_CHANGED', message: `The composer could not be opened: ${error.message}` };
    }

    const modal = await selectors.resolve(page, 'composer.modal', { timeoutMs: 20000 });
    if (!modal) {
        return { ok: false, code: 'FACEBOOK_UI_CHANGED', message: 'The composer dialog did not open.' };
    }

    return { ok: true };
}

async function typeCaption(page, caption) {
    const area = await selectors.resolve(page, 'composer.textArea', { timeoutMs: 20000 });
    if (!area) {
        return { ok: false, code: 'FACEBOOK_UI_CHANGED', message: 'The caption box was not found in the composer.' };
    }

    try {
        await area.click({ timeout: 10000 });
        // Playwright typing, at normal speed. Newlines are typed as presses so
        // multi-paragraph captions keep their shape.
        const lines = caption.split('\n');
        for (let i = 0; i < lines.length; i += 1) {
            if (lines[i]) {
                await area.type(lines[i], { delay: 8 });
            }
            if (i < lines.length - 1) {
                await area.press('Shift+Enter');
            }
        }
    } catch (error) {
        return { ok: false, code: 'UNKNOWN_TRANSIENT', message: `The caption could not be typed: ${error.message}` };
    }

    return { ok: true };
}

async function submit(page, job) {
    const button = await selectors.resolve(page, 'composer.postButton', { timeoutMs: 20000 });
    if (!button) {
        return { ok: false, code: 'FACEBOOK_UI_CHANGED', message: 'The Post button was not found. Stopping rather than pressing other controls.' };
    }

    // If the button is still disabled, Facebook is not ready to accept the post.
    for (let i = 0; i < 60; i += 1) {
        try {
            const disabled = await button.getAttribute('aria-disabled', { timeout: 1000 });
            if (disabled !== 'true') {
                break;
            }
        } catch {
            break;
        }
        await page.waitForTimeout(1000);
    }

    try {
        await button.click({ timeout: 20000 });
    } catch (error) {
        return { ok: false, code: 'UNKNOWN_TRANSIENT', message: `The publish click failed: ${error.message}` };
    }

    // Facebook closes the dialog and shows a confirmation. We do not assume
    // success from the dialog closing alone — verification decides.
    const dialogGone = await waitForDialogToClose(page, 120000);
    if (!dialogGone) {
        return {
            ok: false,
            code: 'UPLOAD_INTERRUPTED',
            message: 'The composer did not close after publishing — the upload may still be running. Nothing was confirmed.',
        };
    }

    const challenge = await challenges.detect(page);
    if (challenge.detected) {
        return {
            ok: false,
            userAction: true,
            code: challenge.code,
            message: challenge.detail || 'Facebook responded with a security check.',
        };
    }

    return { ok: true, jobType: job.job_type };
}

async function waitForDialogToClose(page, timeoutMs) {
    const deadline = Date.now() + timeoutMs;
    while (Date.now() < deadline) {
        const modal = await selectors.resolve(page, 'composer.modal', { timeoutMs: 500 });
        if (!modal) {
            return true;
        }
        await page.waitForTimeout(1000);
    }
    return false;
}

async function closeComposer(page) {
    try {
        const close = await selectors.resolve(page, 'composer.closeDialog', { timeoutMs: 2000 });
        if (close) {
            await close.click({ timeout: 5000 });
        } else {
            await page.keyboard.press('Escape');
        }
        await page.waitForTimeout(1000);
        const discard = page.locator('div[role="dialog"] div[role="button"]:has-text("Discard")').first();
        if (await discard.isVisible({ timeout: 2000 }).catch(() => false)) {
            await discard.click({ timeout: 5000 });
        }
    } catch {
        /* closing is best-effort clean-up, never a failure we report */
    }
}

function buildCaption(content) {
    if (!content) {
        return '';
    }
    const parts = [];
    if (content.caption) {
        parts.push(String(content.caption).trim());
    }
    if (content.link_url) {
        parts.push(String(content.link_url).trim());
    }
    if (content.hashtags) {
        parts.push(String(content.hashtags).trim());
    }
    return parts.filter(Boolean).join('\n\n');
}

/**
 * Reels use the Reels composer. It is a different surface, so it is a separate
 * flow that fails loudly when the layout is not what we expect.
 */
async function publishReel(ctx) {
    const { page, job, mediaFile, progress } = ctx;
    if (!mediaFile) {
        return { ok: false, code: 'MEDIA_MISSING', message: 'A reel needs a video file.' };
    }

    await progress('open_reels', 10, 'Opening the Reels composer');

    const pageRef = job.page;
    const target = pageRef.url
        ? `${pageRef.url.replace(/\/+$/, '')}/reels/create`
        : `https://www.facebook.com/profile.php?id=${pageRef.external_id}`;

    try {
        await page.goto(target, { waitUntil: 'domcontentloaded', timeout: 60000 });
    } catch (error) {
        return { ok: false, code: 'PAGE_LOAD_TIMEOUT', message: `The Reels composer did not load: ${error.message}` };
    }

    const challenge = await challenges.detect(page);
    if (challenge.detected) {
        return { ok: false, userAction: true, code: challenge.code, message: challenge.detail };
    }

    const input = await selectors.resolve(page, 'composer.fileInput', { timeoutMs: 20000, visible: false });
    if (!input) {
        return { ok: false, code: 'FACEBOOK_UI_CHANGED', message: 'The Reels upload input was not found.' };
    }

    await progress('media_upload', 40, 'Uploading the reel');
    const attached = await uploads.attachFile(page, mediaFile, { kind: 'VIDEO' });
    if (!attached.ok) {
        return attached;
    }

    const caption = buildCaption(job.content);
    if (caption) {
        const typed = await typeCaption(page, caption);
        if (!typed.ok) {
            return typed;
        }
    }

    await progress('submitting', 75, 'Publishing the reel');
    const submitted = await submit(page, job);
    if (!submitted.ok) {
        return submitted;
    }

    const verified = await verification.verifyPublication(page, job);
    return verified.verified
        ? { ok: true, resultUrl: verified.url, verified: true }
        : { ok: false, code: 'PUBLISH_VERIFICATION_REQUIRED', message: verified.detail, candidateUrl: verified.candidateUrl };
}

module.exports = { publish, publishReel, buildCaption, openComposer, closeComposer };
