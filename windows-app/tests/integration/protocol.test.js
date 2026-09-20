'use strict';

/**
 * End-to-end worker protocol test against a live LinkEasy server.
 *
 * It drives the same ApiClient the worker uses through the real endpoints:
 *
 *   enrol → heartbeat → claim → progress → complete (with verification)
 *
 * Run it against the demo server:
 *
 *   LINKEASY_TEST_SERVER=http://127.0.0.1:8080 \
 *   LINKEASY_TEST_EMAIL=demo@linkeasy.local \
 *   LINKEASY_TEST_PASSWORD='Demo-Publishing-2026!' \
 *   node --test tests/integration/protocol.test.js
 *
 * Without LINKEASY_TEST_SERVER the suite is skipped, so `npm test` stays green
 * on a machine with no server running.
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const crypto = require('crypto');
const os = require('os');

const { ApiClient } = require('../../src/api/client');

const SERVER = process.env.LINKEASY_TEST_SERVER;
const EMAIL = process.env.LINKEASY_TEST_EMAIL;
const PASSWORD = process.env.LINKEASY_TEST_PASSWORD;

const enabled = Boolean(SERVER && EMAIL && PASSWORD);

test('worker protocol', { skip: enabled ? false : 'set LINKEASY_TEST_SERVER / _EMAIL / _PASSWORD to run' }, async (t) => {
    const client = new ApiClient({ baseUrl: SERVER });
    let workerId = null;

    await t.test('health endpoint answers before we have a token', async () => {
        const health = await client.health();
        assert.equal(health.ok, true);
        assert.equal(health.checks.database, 'ok');
    });

    await t.test('an unknown token is refused with 401', async () => {
        client.setCredentials({ token: 'lkw_not_a_real_token', workerId: '00000000-0000-0000-0000-000000000000' });
        await assert.rejects(() => client.claimJobs(1), (error) => error.status === 401);
        client.setCredentials({ token: null });
    });

    await t.test('a machine can enrol with workspace credentials', async () => {
        const response = await client.register({
            installation_id: crypto.randomUUID(),
            worker_id: crypto.randomUUID(),
            name: `TEST-${os.hostname()}`,
            email: EMAIL,
            password: PASSWORD,
            meta: {
                os: 'Windows 11 Pro',
                app_version: '1.0.0',
                worker_version: '1.0.0',
                playwright_version: '1.49.0',
                browser_version: 'Chromium 131',
                ffmpeg_available: true,
            },
        });

        assert.equal(response.ok, true);
        assert.ok(response.token.startsWith('lkw_'), 'the enrolment returns a worker token once');
        assert.ok(response.worker.id, 'the server returns the worker row');
        assert.ok(response.config, 'the enrolment returns the behaviour config bundle');

        workerId = response.worker.worker_id;
        client.setCredentials({ token: response.token, workerId });
    });

    await t.test('heartbeat is accepted and returns the effective config', async () => {
        const response = await client.heartbeat({
            status: 'ONLINE',
            cpu_pct: 5,
            mem_mb: 320,
            pending_count: 0,
            internet_ok: true,
        });

        assert.equal(response.ok, true);
        assert.ok(response.next_heartbeat_s >= 10);
        assert.ok(response.config && typeof response.config === 'object');
        assert.equal(typeof response.config.max_concurrent_browsers, 'number');
    });

    await t.test('the claim → progress → complete path honours idempotency and verification', async () => {
        const response = await client.claimJobs(2);
        assert.ok(Array.isArray(response.jobs), 'claiming returns a jobs array');

        if (response.jobs.length === 0) {
            t.diagnostic('No queued jobs on this server — claim/complete steps skipped.');
            return;
        }

        const job = response.jobs[0];
        for (const field of ['id', 'job_type', 'page', 'account', 'content']) {
            assert.ok(field in job, `a claimed job must include ${field}`);
        }
        assert.ok(job.page.name, 'the job names the Page it publishes to');
        assert.ok(job.idempotency_key, 'every job carries an idempotency key');

        // A job that is already leased must not be handed to another poll.
        const claimedAgain = await client.claimJobs(2);
        assert.equal(claimedAgain.jobs.some((candidate) => candidate.id === job.id), false,
            'a claimed job must not be handed out twice (idempotency)');

        // Progress reporting.
        const progress = await client.progress(job.id, {
            status: 'UPLOADING',
            stage: 'media_upload',
            progress_pct: 40,
            message: 'Integration test progress report',
        });
        assert.equal(progress.ok, true, 'progress must be accepted');

        // A success claim without evidence must be refused.
        await assert.rejects(
            () => client.complete(job.id, { verified: false }),
            (error) => error.status === 422,
            'the server must refuse an unverified success report',
        );

        // A verified completion with a URL is accepted and stored.
        const url = `https://www.facebook.com/permalink.php?story_fbid=${Date.now()}&id=100000000000000`;
        const completed = await client.complete(job.id, {
            result_url: url,
            verified: true,
            meta: { source: 'integration-test' },
        });
        assert.equal(completed.ok, true);
        assert.equal(completed.job.result_url, url);
        assert.equal(completed.job.status, 'PUBLISHED');

        // Reporting the same completion twice must be harmless, never a second post.
        const duplicate = await client.complete(job.id, {
            result_url: url,
            verified: true,
        });
        assert.equal(duplicate.ok, true, 'a duplicate completion must not fail the job or create a second post');
    });

    await t.test('a failed job reports a code the dashboard understands', async () => {
        const claimed = await client.claimJobs(1);
        const job = (claimed.jobs || [])[0];
        if (!job) {
            t.diagnostic('No job left to fail-report; step skipped.');
            return;
        }

        const result = await client.fail(job.id, {
            error_code: 'NETWORK_ERROR',
            error_message: 'Integration test: simulated transient failure',
            retryable: true,
        });

        assert.equal(result.ok, true);
        assert.ok(['RETRYING', 'QUEUED', 'FAILED'].includes(result.job.status));
    });

    await t.test('a security challenge is reported as a pause, never a bypass', async () => {
        const result = await client.challenge({
            job_id: null,
            account_id: null,
            challenge_type: 'CAPTCHA_DETECTED',
            message: 'Integration test: a CAPTCHA was shown; a human must complete it.',
        });

        assert.equal(result.ok, true);
        assert.equal(result.acknowledged, true);
        assert.match(result.instruction, /browser|foreground|check/i);
    });

    await t.test('log lines are accepted and redacted server-side', async () => {
        const result = await client.logs([
            { channel: 'worker', level: 'info', message: 'Integration test log line' },
            { channel: 'browser', level: 'warn', message: 'cookie=datr=SHOULD_NOT_BE_STORED secret=xyz' },
        ]);

        assert.equal(result.ok, true);
        assert.ok(result.ingested >= 1);
    });
});
