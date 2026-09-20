'use strict';

/**
 * Robust file upload.
 *
 * Playwright's file chooser handling is the only mechanism used: we wait for the
 * input, then set files. There is no simulated mouse-tracing, no randomised
 * delays intended to look human, and no OS-level input injection (prompt §24).
 *
 * Large media never passes through memory: the file is streamed from disk by the
 * browser, and any media we had to download first was fetched with resume
 * support into the local cache (prompt §53).
 */

const fs = require('fs');
const path = require('path');
const selectors = require('../facebook/selectors');
const { Logger } = require('../logging/logger');

const log = new Logger({ channel: 'browser' });

/**
 * Attach a file to the open composer.
 *
 * @returns {Promise<{ok:boolean,code?:string,detail?:string}>}
 */
async function attachFile(page, file, { kind = 'IMAGE', timeoutMs = 15 * 60 * 1000 } = {}) {
    if (!fs.existsSync(file)) {
        return { ok: false, code: 'MEDIA_MISSING', detail: 'The media file is not on this machine.' };
    }

    const size = fs.statSync(file).size;
    if (size === 0) {
        return { ok: false, code: 'MEDIA_INVALID', detail: 'The media file is empty.' };
    }

    // Open the Photo/video picker when present; the input itself is hidden and
    // can be addressed directly, which is the documented Playwright approach.
    const picker = await selectors.resolve(page, 'composer.photoVideoButton', { timeoutMs: 8000 });
    if (picker) {
        await picker.click({ timeout: 10000 });
    }

    let input = await selectors.resolve(page, 'composer.fileInput', { timeoutMs: 15000, visible: false });

    if (!input) {
        // Some layouts only create the input after the picker opens.
        const fallback = await selectors.resolve(page, 'composer.photoVideoButton', { timeoutMs: 5000 });
        if (fallback) {
            await fallback.click({ timeout: 10000 });
            input = await selectors.resolve(page, 'composer.fileInput', { timeoutMs: 15000, visible: false });
        }
    }

    if (!input) {
        return {
            ok: false,
            code: 'FACEBOOK_UI_CHANGED',
            detail: 'The media picker could not be found in the composer. Stopping rather than clicking other controls.',
        };
    }

    try {
        await input.setInputFiles(file, { timeout: 120000 });
    } catch (error) {
        log.warn('Attaching media failed', { file: path.basename(file), error: String(error) });
        return { ok: false, code: 'UPLOAD_INTERRUPTED', detail: `The file could not be attached: ${error.message}` };
    }

    log.info('Media attached to the composer', {
        file: path.basename(file),
        bytes: size,
        kind,
    });

    return waitForUploadToFinish(page, { kind, timeoutMs });
}

/**
 * Wait for Facebook to finish processing the upload.
 *
 * We wait for a positive signal (preview / progress bar disappearing) and give
 * up with UPLOAD_INTERRUPTED if the composer still looks busy. We never press
 * Post while an upload is visibly in flight.
 */
async function waitForUploadToFinish(page, { kind, timeoutMs }) {
    const deadline = Date.now() + timeoutMs;
    let sawProgress = false;

    while (Date.now() < deadline) {
        const progress = await selectors.resolve(page, 'composer.uploadProgress', { timeoutMs: 400, visible: true });
        if (progress) {
            sawProgress = true;
            await page.waitForTimeout(1000);
            continue;
        }

        const ready = await selectors.resolve(page, 'composer.mediaReadyMarker', { timeoutMs: 1500, visible: true });
        if (ready) {
            // A video may render its preview before processing completes; the
            // Post button becoming enabled is the reliable final signal, and the
            // publish step checks that separately.
            return { ok: true, waitedForProgress: sawProgress };
        }

        await page.waitForTimeout(1000);
    }

    return {
        ok: false,
        code: 'UPLOAD_INTERRUPTED',
        detail: `The ${kind === 'VIDEO' ? 'video' : 'image'} did not finish processing in time. Nothing was posted.`,
    };
}

module.exports = { attachFile, waitForUploadToFinish };
