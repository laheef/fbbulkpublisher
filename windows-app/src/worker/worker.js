'use strict';

/**
 * The worker: registration, heartbeat, job claiming and concurrency control.
 *
 * Capacity rules (prompt §10, §12, §45):
 *   - at most MAX_CONCURRENT_JOBS jobs in flight
 *   - at most MAX_CONCURRENT_BROWSERS Chromium instances
 *   - at most MAX_JOBS_PER_WORKER jobs held per poll window
 *   - a Page never gets two live publishing jobs at once (the server also
 *     enforces this; the worker honours it locally to avoid wasted work)
 *
 * Everything the worker does is observable: it emits events the tray app and
 * the local dashboard render, and mirrors its log tail to the server.
 */

const EventEmitter = require('events');
const os = require('os');

const { ApiClient } = require('../api/client');
const { BrowserManager } = require('../browser/browser-manager');
const { JobRunner } = require('./job-runner');
const mediaCache = require('./media-cache');
const paths = require('../config/paths');
const settings = require('../config/settings');
const credentials = require('../security/credential-store');
const { Logger } = require('../logging/logger');

const log = new Logger({ channel: 'worker' });

const STATE = {
    OFFLINE: 'OFFLINE',
    STARTING: 'STARTING',
    ONLINE: 'ONLINE',
    BUSY: 'BUSY',
    PAUSED: 'PAUSED',
    DEGRADED: 'DEGRADED',
    UPDATING: 'UPDATING',
    ERROR: 'ERROR',
};

class Worker extends EventEmitter {
    constructor(options = {}) {
        super();
        this.state = STATE.OFFLINE;
        this.credentials = options.credentials || credentials.loadWorkerCredentials();
        this.installationId = this.credentials?.installationId || null;
        this.workerId = this.credentials?.workerId || null;

        this.api = new ApiClient({
            baseUrl: this.credentials?.serverUrl || settings.get('server.baseUrl', ''),
            token: this.credentials?.token || null,
            workerId: this.workerId,
            onConnectivityChange: (info) => this.onConnectivityChange(info),
        });

        this.browsers = new BrowserManager({
            maxBrowsers: settings.get('worker.maxConcurrentBrowsers', 2),
            idleTimeoutSeconds: settings.get('worker.idleBrowserTimeoutSeconds', 300),
        });

        this.runner = new JobRunner({
            api: this.api,
            browserManager: this.browsers,
            worker: this,
            notify: (payload) => this.emit('notify', payload),
        });

        this.activeJobs = new Map();      // jobId -> { startedAt, job }
        this.pausedUntil = null;
        this.pausedReason = null;
        this.paused = false;
        this.stopping = false;
        this.timers = {};
        this.stats = { claimed: 0, published: 0, failed: 0, retries: 0, startedAt: Date.now() };
        this.lastHeartbeatAt = null;
        this.lastError = null;
    }

    // ---- lifecycle -------------------------------------------------------

    async start() {
        if (!this.api.baseUrl) {
            throw new Error('No workspace is configured yet. Connect this PC to your LinkEasy workspace first.');
        }
        if (!this.credentials?.token) {
            throw new Error('This PC is not registered. Enrol it from the tray app first.');
        }

        this.setState(STATE.STARTING);
        log.info('Starting automation worker', { workerId: this.workerId });

        try {
            const config = await this.api.config();
            settings.applyServerConfig(config.config || config);
            this.applyLocalSettings();
        } catch (error) {
            log.warn('Could not load the server configuration; using local defaults.', { error: String(error) });
        }

        await this.heartbeat().catch((error) => log.warn('The first heartbeat failed; will retry.', { error: String(error) }));

        this.timers.heartbeat = setInterval(() => this.safe(() => this.heartbeat()), this.heartbeatIntervalMs());
        this.timers.poll = setInterval(() => this.safe(() => this.poll()), this.pollIntervalMs());
        this.timers.housekeeping = setInterval(() => this.safe(() => this.housekeeping()), 6 * 60 * 60 * 1000);

        this.setState(STATE.ONLINE);
        this.emit('state', this.status());
        return this.status();
    }

    async stop({ drain = true, timeoutMs = 120000 } = {}) {
        this.stopping = true;
        this.setState(STATE.OFFLINE);
        Object.values(this.timers).forEach(clearInterval);
        this.timers = {};

        if (drain && this.activeJobs.size > 0) {
            const deadline = Date.now() + timeoutMs;
            while (this.activeJobs.size > 0 && Date.now() < deadline) {
                await new Promise((resolve) => setTimeout(resolve, 500));
            }
        }

        await this.browsers.shutdown();
        log.info('Worker stopped', { stats: this.stats });
        this.emit('state', this.status());
    }

    applyLocalSettings() {
        this.browsers.maxBrowsers = settings.get('worker.maxConcurrentBrowsers', 2);
        this.browsers.idleTimeoutMs = settings.get('worker.idleBrowserTimeoutSeconds', 300) * 1000;

        const level = settings.get('diagnostics.verboseLogs', false) ? 'debug' : 'info';
        log.setLevel(level);
    }

    setState(state) {
        if (this.state !== state) {
            this.state = state;
            this.emit('state', this.status());
        }
    }

    /** Pause this worker because a human is needed. */
    pauseForOperator(info) {
        this.paused = true;
        this.pausedReason = info;
        this.setState(STATE.PAUSED);
        log.warn('Worker paused for operator action', info);
        this.emit('paused', info);
    }

    resume() {
        if (!this.paused) {
            return;
        }
        this.paused = false;
        this.pausedReason = null;
        this.setState(this.activeJobs.size > 0 ? STATE.BUSY : STATE.ONLINE);
        log.info('Worker resumed by the operator.');
        this.emit('resumed', {});
    }

    // ---- heartbeat -------------------------------------------------------

    heartbeatIntervalMs() {
        return Math.max(10, settings.get('server.heartbeatSeconds', 20)) * 1000;
    }

    pollIntervalMs() {
        return Math.max(5, settings.get('server.pollSeconds', 15)) * 1000;
    }

    async heartbeat() {
        const payload = {
            status: this.state === STATE.PAUSED ? 'PAUSED' : 'ONLINE',
            active_jobs: this.activeJobs.size,
            pending_count: this.activeJobs.size,
            browsers: this.browsers.activeCount,
            cpu_pct: cpuPercent(),
            mem_mb: Math.round(process.memoryUsage().rss / 1048576),
            internet_ok: !this.api.offline,
            paused: this.paused,
            app_version: settings.get('app.version', '1.0.0'),
            worker_version: settings.get('app.workerVersion', '1.0.0'),
            uptime_s: Math.round((Date.now() - this.stats.startedAt) / 1000),
            stats: {
                published: this.stats.published,
                failed: this.stats.failed,
                active: this.activeJobs.size,
            },
            challenges: this.pausedReason ? [this.pausedReason.code] : [],
        };

        const response = await this.api.heartbeat(payload);
        this.lastHeartbeatAt = new Date().toISOString();

        if (response && response.config) {
            const before = settings.serverConfig();
            settings.applyServerConfig(response.config);
            this.applyLocalSettings();

            if (JSON.stringify(before) !== JSON.stringify(settings.serverConfig())) {
                log.info('Configuration updated by the dashboard.');
                this.emit('config', settings.serverConfig());
            }
        }

        if (response && response.is_paused && !this.paused) {
            this.paused = true;
            this.pausedReason = { code: 'SERVER_PAUSED' };
            this.setState(STATE.PAUSED);
        } else if (response && response.is_paused === false && this.paused && this.pausedReason?.code === 'SERVER_PAUSED') {
            this.resume();
        }

        // Mirror the log tail so the dashboard shows what happened on this PC.
        if (response && response.want_logs) {
            await this.flushLogs();
        }

        this.emit('heartbeat', { at: this.lastHeartbeatAt, response });
        return response;
    }

    onConnectivityChange({ online }) {
        if (!online) {
            this.setState(STATE.DEGRADED);
            this.emit('offline', {});
        } else if (this.state === STATE.DEGRADED) {
            this.setState(this.activeJobs.size > 0 ? STATE.BUSY : STATE.ONLINE);
            this.emit('online', {});
        }
    }

    async flushLogs() {
        const lines = log.drain(100);
        if (lines.length === 0) {
            return;
        }
        try {
            await this.api.logs(lines);
        } catch (error) {
            // Put them back so nothing is lost while offline.
            lines.forEach((line) => log.buffer.push(line));
            log.warn('Log lines could not be delivered; they will be sent later.', { error: String(error) });
        }
    }

    // ---- claiming and running jobs ---------------------------------------

    capacity() {
        const maxJobs = settings.get('worker.maxConcurrentJobs', 2);
        const maxPerWorker = settings.get('worker.maxJobsPerWorker', 10);
        const free = Math.max(0, Math.min(maxJobs, maxPerWorker) - this.activeJobs.size);
        const browserRoom = Math.max(0, this.browsers.maxBrowsers - this.browsers.activeCount);
        return Math.min(free, Math.max(1, browserRoom + this.activeJobs.size));
    }

    async poll() {
        if (this.stopping || this.paused || this.api.offline) {
            return;
        }

        const room = this.capacity();
        if (room <= 0) {
            return;
        }

        let response;
        try {
            response = await this.api.claimJobs(room);
        } catch (error) {
            if (error.status === 401 || error.status === 403) {
                this.setState(STATE.ERROR);
                this.lastError = 'This PC is no longer authorised. Re-enrol it from the tray app.';
                this.emit('unauthorised', { message: this.lastError });
                this.stop({ drain: false }).catch(() => {});
                return;
            }
            log.warn('Claiming jobs failed', { error: String(error) });
            return;
        }

        const jobs = (response && response.jobs) || [];
        if (jobs.length === 0) {
            return;
        }

        log.info(`Claimed ${jobs.length} job(s).`);
        this.stats.claimed += jobs.length;

        for (const job of jobs) {
            if (this.stopping) {
                break;
            }
            this.activeJobs.set(job.id, { job, startedAt: Date.now() });
            this.setState(STATE.BUSY);
            this.emit('job:start', { jobId: job.id, job });

            // Jobs are run without awaiting each other so concurrency is real,
            // while the caps above keep the machine from being overwhelmed.
            this.runner.run(job)
                .then((outcome) => {
                    if (outcome.ok) {
                        this.stats.published += 1;
                    } else {
                        this.stats.failed += 1;
                        if (outcome.code && !outcome.code.startsWith('USER')) {
                            this.stats.retries += 1;
                        }
                    }
                })
                .catch((error) => log.error('Job runner crashed', { job: job.id, error: String(error) }))
                .finally(() => {
                    this.activeJobs.delete(job.id);
                    if (!this.paused && !this.stopping) {
                        this.setState(this.activeJobs.size > 0 ? STATE.BUSY : STATE.ONLINE);
                    }
                });
        }
    }

    // ---- housekeeping ----------------------------------------------------

    async housekeeping() {
        const pruned = mediaCache.prune();
        const stale = mediaCache.pruneStale({ olderThanHours: settings.get('media.keepFailedMediaHours', 72) });
        this.pruneScreenshots();
        this.pruneTraces();

        log.info('Local housekeeping finished', { pruned: pruned.removed, stale, ...pruned });
        this.emit('housekeeping', { pruned, stale });
    }

    pruneScreenshots() {
        const days = settings.get('diagnostics.screenshotRetentionDays', 30);
        pruneDirectory(paths.screenshots(), days);
    }

    pruneTraces() {
        const days = settings.get('diagnostics.traceRetentionDays', 14);
        pruneDirectory(paths.traces(), days);
    }

    // ---- reporting -------------------------------------------------------

    status() {
        return {
            state: this.state,
            paused: this.paused,
            pausedReason: this.pausedReason,
            workerId: this.workerId,
            installationId: this.installationId,
            serverUrl: this.api.baseUrl,
            online: !this.api.offline,
            lastHeartbeatAt: this.lastHeartbeatAt,
            lastError: this.lastError,
            activeJobs: [...this.activeJobs.entries()].map(([id, record]) => ({
                id,
                type: record.job.job_type,
                page: record.job.page ? record.job.page.name : null,
                seconds: Math.round((Date.now() - record.startedAt) / 1000),
            })),
            browsers: this.browsers.status(),
            limits: {
                maxConcurrentJobs: settings.get('worker.maxConcurrentJobs', 2),
                maxConcurrentBrowsers: settings.get('worker.maxConcurrentBrowsers', 2),
                maxJobsPerWorker: settings.get('worker.maxJobsPerWorker', 10),
            },
            stats: { ...this.stats, uptimeSeconds: Math.round((Date.now() - this.stats.startedAt) / 1000) },
        };
    }

    /** Register this machine with the workspace (first run / re-enrol). */
    async enrol({ serverUrl, email, password, name }) {
        const client = new ApiClient({ baseUrl: serverUrl });
        const installationId = this.installationId || require('crypto').randomUUID();
        const workerId = this.workerId || require('crypto').randomUUID();

        const response = await client.register({
            installation_id: installationId,
            worker_id: workerId,
            name: name || os.hostname(),
            email,
            password,
            meta: {
                os: `${os.type()} ${os.release()}`,
                app_version: settings.get('app.version', '1.0.0'),
                worker_version: settings.get('app.workerVersion', '1.0.0'),
                playwright_version: require('playwright/package.json').version,
                ffmpeg_available: true,
                hostname: os.hostname(),
                arch: os.arch(),
            },
        });

        this.installationId = installationId;
        this.workerId = response.worker.worker_id || workerId;
        this.credentials = {
            token: response.token,
            workerId: response.worker.id,
            installationId,
            serverUrl,
        };

        credentials.saveWorkerCredentials({
            token: response.token,
            workerId: String(response.worker.id),
            installationId,
            serverUrl,
        });

        this.api.setCredentials({ baseUrl: serverUrl, token: response.token, workerId: String(response.worker.id) });
        settings.set('server.baseUrl', serverUrl);

        if (response.config) {
            settings.applyServerConfig(response.config);
        }

        log.info('This PC is now enrolled in the workspace.', { worker: response.worker.id });
        return response;
    }

    async safe(operation) {
        try {
            await operation();
        } catch (error) {
            log.warn('Background task failed', { error: String(error) });
        }
    }
}

function cpuPercent() {
    const load = os.loadavg()[0];
    const cores = os.cpus().length || 1;
    return Math.min(100, Math.round((load / cores) * 100));
}

function pruneDirectory(dir, days) {
    const fs = require('fs');
    if (!fs.existsSync(dir) || !days) {
        return 0;
    }
    const cutoff = Date.now() - days * 86400 * 1000;
    let removed = 0;
    for (const name of fs.readdirSync(dir)) {
        const target = require('path').join(dir, name);
        try {
            if (fs.statSync(target).mtimeMs < cutoff) {
                fs.rmSync(target, { recursive: true, force: true });
                removed += 1;
            }
        } catch {
            /* ignore */
        }
    }
    return removed;
}

module.exports = { Worker, STATE };
