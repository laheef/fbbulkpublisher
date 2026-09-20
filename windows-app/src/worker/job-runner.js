'use strict';

/**
 * Job runner — turns one claimed job into one reported outcome.
 *
 * Non-negotiables implemented here:
 *
 *   Idempotency (prompt §21, §22): before a retry of an uncertain attempt we
 *   look for the post on the Page. If it is already there we report success for
 *   that existing post instead of publishing a second copy.
 *
 *   Honest failure codes: every exit path names what happened. Permanent
 *   problems stop immediately; transient problems are retried by the server
 *   with backoff (prompt §18, §19).
 *
 *   Challenges pause, never bypass (prompt §20).
 */

const fs = require('fs');
const path = require('path');

const composer = require('../facebook/composer');
const accountJobs = require('../facebook/account-jobs');
const verification = require('../browser/verification');
const challenges = require('../browser/challenge-detector');
const sessionManager = require('../browser/session-manager');
const mediaCache = require('./media-cache');
const paths = require('../config/paths');
const settings = require('../config/settings');
const { Logger } = require('../logging/logger');

const log = new Logger({ channel: 'worker' });

/** Error codes after which a publish attempt may have reached Facebook. */
const UNCERTAIN_CODES = new Set([
    'BROWSER_CRASHED',
    'BROWSER_LAUNCH_FAILED',
    'WORKER_RESTARTED',
    'LEASE_EXPIRED',
    'UPLOAD_INTERRUPTED',
    'TIMEOUT',
    'NETWORK_ERROR',
    'SERVER_UNREACHABLE',
    'UNKNOWN_TRANSIENT',
]);

const PERMANENT_CODES = new Set([
    'FACEBOOK_UI_CHANGED',
    'PUBLISH_VERIFICATION_REQUIRED',
    'ACCOUNT_DISABLED',
    'PAGE_NOT_FOUND',
    'PAGE_NO_PERMISSION',
    'MEDIA_INVALID',
    'MEDIA_MISSING',
    'POST_EMPTY',
    'UNSUPPORTED_MEDIA',
    'JOB_CANCELLED',
    'DUPLICATE_PUBLICATION_CONFIRMED',
    'PERMANENT_PLATFORM_REJECTION',
]);

class JobRunner {
    constructor(deps) {
        this.api = deps.api;
        this.browsers = deps.browserManager;
        this.worker = deps.worker;
        this.notify = deps.notify || (() => {});
    }

    async run(job) {
        const started = Date.now();
        const runner = this;
        let session = null;
        let mediaFile = null;
        let mediaReleased = false;

        const progress = async (stage, pct, message) => {
            try {
                await runner.api.progress(job.id, { status: 'PROCESSING', stage, progress_pct: pct, message });
            } catch (error) {
                log.warn('Progress could not be reported', { job: job.id, stage, error: String(error) });
            }
            this.worker.emit('job:progress', { jobId: job.id, stage, progress: pct, message });
        };

        try {
            if (job.job_type === 'VERIFY_SESSION' || job.job_type === 'DETECT_PAGES') {
                session = await this.browsers.acquire(job.account);
                const outcome = job.job_type === 'VERIFY_SESSION'
                    ? await accountJobs.verifySession({ session, job, progress })
                    : await accountJobs.detectPages({ session, job, progress });

                return await this.finish(job, outcome, { verified: job.job_type === 'VERIFY_SESSION' });
            }

            // ---- publishing jobs -------------------------------------------
            session = await this.browsers.acquire(job.account);

            const sessionState = await sessionManager.checkSessionState(session);
            if (sessionState === 'AUTH_REQUIRED') {
                return await this.finish(job, {
                    ok: false,
                    userAction: true,
                    code: 'ACCOUNT_REAUTH_REQUIRED',
                    message: 'The Facebook session for this account has expired. Reconnect it from the tray app; you will sign in yourself.',
                });
            }
            if (sessionState === 'CHALLENGE_REQUIRED') {
                return await this.finish(job, {
                    ok: false,
                    userAction: true,
                    code: 'SECURITY_CHALLENGE',
                    message: 'Facebook is asking for verification on this account before posting.',
                });
            }

            // Before a retry, check whether the previous attempt already made it
            // through — the difference between a safe retry and a duplicate.
            const uncertainRetry = job.attempts > 1
                && job.last_error_code
                && UNCERTAIN_CODES.has(job.last_error_code);

            if (uncertainRetry) {
                await progress('precheck', 10, 'Checking whether the previous attempt already published');
                const existing = await this.checkForExistingPost(session, job);

                if (existing.found) {
                    log.info('A post from a previous attempt already exists; reporting it instead of publishing again.', {
                        job: job.id,
                        url: existing.url,
                    });
                    return await this.finish(job, {
                        ok: true,
                        resultUrl: existing.url,
                        verified: true,
                        evidence: 'pre_retry_scan',
                        message: 'An earlier attempt had already published this post; it was not published again.',
                    });
                }
            }

            const media = await mediaCache.ensureLocalMedia(job, this.api, {
                onProgress: (pct) => progress('media_download', 10 + Math.round(pct * 0.15), `Downloading media (${pct}%)`),
            });
            if (!media.ok) {
                return await this.finish(job, { ok: false, code: media.code, message: media.message });
            }
            mediaFile = media.file;

            const context = {
                page: await sessionManager.ensurePage(session),
                job,
                mediaFile,
                progress,
            };

            const outcome = job.job_type === 'PUBLISH_REEL'
                ? await composer.publishReel(context)
                : await composer.publish(context);

            return await this.finish(job, outcome, { verified: outcome.verified === true });
        } catch (error) {
            const crashed = isBrowserCrash(error);

            if (crashed && session) {
                log.warn('The browser died mid-job; recovering.', { job: job.id });
                try {
                    await this.browsers.recover(job.account);
                } catch (recoveryError) {
                    log.error('Browser recovery failed', { job: job.id, error: String(recoveryError) });
                }
            }

            return await this.finish(job, {
                ok: false,
                code: crashed ? 'BROWSER_CRASHED' : 'UNKNOWN_TRANSIENT',
                message: crashed
                    ? 'The browser closed unexpectedly. The profile was restored and the job will be retried.'
                    : `The job could not be completed: ${error.message}`,
            });
        } finally {
            if (mediaFile && !mediaReleased) {
                mediaCache.release(mediaFile, { keep: !settings.get('media.cleanupAfterPublish', true) });
            }
            if (session) {
                await this.browsers.release(session);
            }
            log.info('Job finished', {
                job: job.id,
                type: job.job_type,
                durationMs: Date.now() - started,
            });
        }
    }

    /**
     * Look for a post that matches this job on the Page timeline or at a URL the
     * server already knows about.
     */
    async checkForExistingPost(session, job) {
        const page = await sessionManager.ensurePage(session);

        if (job.result_url) {
            const confirmed = await verification.confirmExistingPost(page, job.result_url);
            if (confirmed.exists) {
                return { found: true, url: job.result_url };
            }
        }

        const navigation = await sessionManager.actAsPage(page, job.page);
        if (!navigation.ok) {
            return { found: false };
        }

        // Wait for the timeline to render before scanning.
        await page.waitForTimeout(2500);
        const result = await verification.verifyPublication(page, job, { waitMs: 20000 });
        return result.verified ? { found: true, url: result.url } : { found: false };
    }

    /** Report the outcome to the server, with the correct semantics. */
    async finish(job, outcome, { verified = false } = {}) {
        if (outcome.ok) {
            const payload = {
                result_url: outcome.resultUrl || null,
                verified: verified || outcome.verified === true,
                meta: {
                    evidence: outcome.evidence || null,
                    state: outcome.state || null,
                    message: outcome.message || null,
                },
            };

            if (job.job_type === 'DETECT_PAGES' && outcome.pages) {
                payload.pages = outcome.pages;
            }

            // The server rejects a success without a URL or a verified flag; this
            // keeps the worker from claiming something it cannot show.
            if (job.job_type.startsWith('PUBLISH') && !payload.result_url && !payload.verified) {
                outcome = {
                    ok: false,
                    code: 'PUBLISH_VERIFICATION_REQUIRED',
                    message: 'The post could not be confirmed, so it is not being reported as published.',
                };
            } else {
                try {
                    await this.api.complete(job.id, payload);
                    this.worker.emit('job:done', { jobId: job.id, ok: true, url: payload.result_url });
                    if (job.job_type.startsWith('PUBLISH')) {
                        this.notify({
                            title: 'Post published',
                            body: `${job.page?.name || 'Page'} — published successfully.`,
                        });
                    }
                    return { ok: true };
                } catch (error) {
                    log.error('The server did not accept the job completion', { job: job.id, error: String(error) });
                    return { ok: false, code: 'SERVER_UNREACHABLE', message: String(error.message) };
                }
            }
        }

        const code = outcome.code || 'UNKNOWN_TRANSIENT';
        const message = outcome.message || 'The job did not complete.';

        if (code === 'PUBLISH_VERIFICATION_REQUIRED') {
            try {
                // The server may resolve this itself (e.g. it knows the post URL),
                // otherwise it raises USER_ACTION_REQUIRED for a human.
                const response = await this.api.verify(job.id, {
                    verified: false,
                    reason: code,
                    message,
                    candidate_url: outcome.candidateUrl || null,
                });
                this.worker.emit('job:verify', { jobId: job.id, response });
                await this.captureFailureScreenshot(job, 'verification-required');
                return { ok: false, code, message };
            } catch (error) {
                log.warn('Verification request failed', { job: job.id, error: String(error) });
            }
        }

        if (outcome.userAction) {
            try {
                await this.api.challenge({
                    job_id: job.id,
                    account_id: job.account?.id || job.account_id || null,
                    challenge_type: code,
                    message,
                });
                this.worker.emit('challenge', { jobId: job.id, code, message });
                this.notify({
                    title: 'Action needed',
                    body: `${job.page?.name || 'A Page'} needs you: ${message}`,
                    urgent: true,
                });
                this.worker.pauseForOperator({ jobId: job.id, code });
            } catch (error) {
                log.error('The challenge could not be reported', { job: job.id, error: String(error) });
            }
        }

        const screenshot = await this.captureFailureScreenshot(job, code);

        try {
            await this.api.fail(job.id, {
                error_code: code,
                error_message: message,
                screenshot_path: screenshot ? path.basename(screenshot) : null,
                retryable: !PERMANENT_CODES.has(code) && !outcome.userAction,
            });
        } catch (error) {
            log.error('The failure could not be reported', { job: job.id, error: String(error) });
        }

        this.worker.emit('job:done', { jobId: job.id, ok: false, code, message });
        return { ok: false, code, message };
    }

    /**
     * A screenshot is the most useful diagnostic the operator can look at, so we
     * take one whenever a job fails — and upload it so it shows in the dashboard
     * next to the job (prompt §33).
     */
    async captureFailureScreenshot(job, reason) {
        if (!settings.get('diagnostics.captureScreenshots', true)) {
            return null;
        }

        const session = this.browsers.sessions.get(String(job.account?.id));
        const page = session && session.page && !session.page.isClosed() ? session.page : null;
        if (!page) {
            return null;
        }

        const file = path.join(
            paths.screenshots(),
            `job-${job.id}-${reason}-${Date.now()}.png`,
        );

        try {
            await page.screenshot({ path: file, fullPage: false, timeout: 15000 });
            await this.api.uploadScreenshot(job.id, file).catch((error) => {
                log.warn('The screenshot could not be uploaded', { job: job.id, error: String(error) });
            });
            return file;
        } catch (error) {
            log.warn('Could not capture a screenshot', { job: job.id, error: String(error) });
            return null;
        }
    }
}

function isBrowserCrash(error) {
    const message = String(error && error.message ? error.message : error);
    return /Target closed|Browser has been closed|Protocol error|Session closed|crash|ECONNRESET/i.test(message);
}

module.exports = { JobRunner, UNCERTAIN_CODES, PERMANENT_CODES };
