'use strict';

/**
 * HTTPS client for the LinkEasy control plane.
 *
 * Responsibilities:
 *   - worker authentication (bearer token + worker id headers)
 *   - exponential backoff when the server or the internet is unavailable
 *   - never losing work: a request that is not confirmed is retried, and job
 *     reporting is idempotent on the server side (prompt §17, §44)
 */

const https = require('https');
const http = require('http');
const { URL } = require('url');
const fs = require('fs');
const path = require('path');
const { Logger } = require('../logging/logger');
const settings = require('../config/settings');

const logger = new Logger({ channel: 'app' });

class ApiError extends Error {
    constructor(message, status, payload) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.payload = payload || null;
    }
}

class ApiClient {
    constructor(options = {}) {
        this.baseUrl = (options.baseUrl || '').replace(/\/+$/, '');
        this.token = options.token || null;
        this.workerId = options.workerId || null;
        this.timeoutMs = options.timeoutMs || settings.get('server.requestTimeoutMs', 30000);
        this.offline = false;
        this.consecutiveFailures = 0;
        this.onConnectivityChange = options.onConnectivityChange || (() => {});
    }

    setCredentials({ baseUrl, token, workerId }) {
        if (baseUrl) {
            this.baseUrl = baseUrl.replace(/\/+$/, '');
        }
        if (token !== undefined) {
            this.token = token;
        }
        if (workerId !== undefined) {
            this.workerId = workerId;
        }
    }

    headers(extra = {}) {
        const headers = {
            Accept: 'application/json',
            'User-Agent': `LinkEasyPublisher/${settings.get('app.version', '1.0.0')} (Windows)`,
            ...extra,
        };
        if (this.token) {
            headers.Authorization = `Bearer ${this.token}`;
        }
        if (this.workerId) {
            headers['X-LinkEasy-Worker'] = this.workerId;
        }
        return headers;
    }

    /** Raw request with backoff-aware error signalling. */
    request(method, route, body, options = {}) {
        return new Promise((resolve, reject) => {
            let url;
            try {
                url = new URL(route.startsWith('http') ? route : `${this.baseUrl}${route}`);
            } catch (error) {
                reject(new ApiError(`Invalid URL for ${route}`, 0, null));
                return;
            }

            const isHttps = url.protocol === 'https:';
            const transport = isHttps ? https : http;
            const payload = body === undefined || body === null ? null : Buffer.from(JSON.stringify(body), 'utf8');

            const headers = this.headers(payload
                ? { 'Content-Type': 'application/json', 'Content-Length': String(payload.length) }
                : {});

            const request = transport.request({
                method,
                hostname: url.hostname,
                port: url.port || (isHttps ? 443 : 80),
                path: `${url.pathname}${url.search}`,
                headers,
                timeout: options.timeoutMs || this.timeoutMs,
                rejectUnauthorized: settings.get('server.verifyTls', true) !== false,
            }, (response) => {
                const chunks = [];
                response.on('data', (chunk) => chunks.push(chunk));
                response.on('end', () => {
                    const raw = Buffer.concat(chunks).toString('utf8');
                    let parsed = null;
                    try {
                        parsed = raw ? JSON.parse(raw) : null;
                    } catch {
                        parsed = { raw };
                    }

                    this.markOnline();

                    if (response.statusCode >= 200 && response.statusCode < 300) {
                        resolve(parsed);
                        return;
                    }

                    const message = (parsed && (parsed.error || parsed.message)) || `HTTP ${response.statusCode}`;
                    const error = new ApiError(message, response.statusCode, parsed);

                    // 401/403 mean our token is wrong — surface it, never retry blindly.
                    if (response.statusCode !== 401 && response.statusCode !== 403) {
                        logger.warn('API request failed', { route, status: response.statusCode, message });
                    }
                    reject(error);
                });
            });

            request.on('timeout', () => {
                request.destroy(new ApiError('The request timed out.', 0, null));
            });

            request.on('error', (error) => {
                this.markOffline();
                reject(error instanceof ApiError ? error : new ApiError(`Network error: ${error.message}`, 0, null));
            });

            if (payload) {
                request.write(payload);
            }
            request.end();
        });
    }

    markOffline() {
        this.consecutiveFailures += 1;
        if (!this.offline) {
            this.offline = true;
            logger.warn('Connection lost — the server could not be reached. Retrying.');
            this.onConnectivityChange({ online: false, failures: this.consecutiveFailures });
        }
    }

    markOnline() {
        if (this.offline) {
            logger.info('Connection restored.');
            this.onConnectivityChange({ online: true, failures: this.consecutiveFailures });
        }
        this.consecutiveFailures = 0;
        this.offline = false;
    }

    /** Exponential backoff with jitter, capped at maxBackoffSeconds. */
    backoffDelay(attempt) {
        const max = settings.get('server.maxBackoffSeconds', 300);
        const base = Math.min(max, 2 ** Math.min(attempt, 8));
        return Math.round((base + Math.random() * 2) * 1000);
    }

    /** Retrying wrapper for read paths. Writes are retried only when safe. */
    async withRetry(operation, { attempts = 5, idempotent = true, label = 'request' } = {}) {
        let lastError = null;
        for (let attempt = 0; attempt < attempts; attempt += 1) {
            try {
                return await operation();
            } catch (error) {
                lastError = error;
                const retryable = (error.status === 0 || error.status >= 500 || error.status === 429);

                if (!retryable || (!idempotent && attempt > 0)) {
                    throw error;
                }
                if (attempt === attempts - 1) {
                    break;
                }

                const delay = this.backoffDelay(attempt);
                logger.warn(`Retrying ${label} in ${Math.round(delay / 1000)}s`, { attempt: attempt + 1, reason: error.message });
                await sleep(delay);
            }
        }
        throw lastError;
    }

    // ---- endpoints -------------------------------------------------------

    register(payload) {
        return this.request('POST', '/worker/register', payload, { timeoutMs: 45000 });
    }

    heartbeat(payload) {
        return this.withRetry(() => this.request('POST', '/worker/heartbeat', payload), { label: 'heartbeat' });
    }

    config() {
        return this.withRetry(() => this.request('GET', '/worker/config'), { label: 'config' });
    }

    claimJobs(limit) {
        return this.withRetry(() => this.request('GET', `/worker/jobs?limit=${limit}`), { label: 'claim' });
    }

    progress(jobId, payload) {
        return this.request('POST', `/worker/jobs/${jobId}/progress`, payload);
    }

    complete(jobId, payload) {
        return this.request('POST', `/worker/jobs/${jobId}/complete`, payload);
    }

    fail(jobId, payload) {
        return this.request('POST', `/worker/jobs/${jobId}/fail`, payload);
    }

    verify(jobId, payload) {
        return this.request('POST', `/worker/jobs/${jobId}/verify`, payload);
    }

    challenge(payload) {
        return this.request('POST', '/worker/challenge', payload);
    }

    logs(lines) {
        return this.request('POST', '/worker/logs', { lines });
    }

    createAccount(label) {
        return this.request('POST', '/worker/accounts', { label });
    }

    syncPages(accountId, pages, accountInfo = {}) {
        return this.request('POST', `/worker/accounts/${accountId}/pages`, {
            pages,
            account_name: accountInfo.name,
            account_id: accountInfo.id,
        });
    }

    accountStatus(accountId, status, extra = {}) {
        return this.request('POST', `/worker/accounts/${accountId}/status`, { status, ...extra });
    }

    reportMediaProbe(mediaId, probe) {
        return this.request('POST', `/worker/media/${mediaId}/probe`, probe);
    }

    /** Health probe that does not require a token. */
    async health() {
        const previous = this.token;
        this.token = null;
        try {
            return await this.request('GET', '/health', null, { timeoutMs: 8000 });
        } finally {
            this.token = previous;
        }
    }

    /** Download a media file to disk with resume support (prompt §53). */
    downloadMedia(jobId, targetFile, onProgress) {
        return new Promise((resolve, reject) => {
            const start = fs.existsSync(targetFile) ? fs.statSync(targetFile).size : 0;
            const url = new URL(`${this.baseUrl}/worker/jobs/${jobId}/media`);
            const transport = url.protocol === 'https:' ? https : http;

            const headers = this.headers({ Accept: '*/*' });
            if (start > 0) {
                headers.Range = `bytes=${start}-`;
            }

            const request = transport.request({
                method: 'GET',
                hostname: url.hostname,
                port: url.port || (url.protocol === 'https:' ? 443 : 80),
                path: url.pathname,
                headers,
                timeout: 0,
                rejectUnauthorized: settings.get('server.verifyTls', true) !== false,
            }, (response) => {
                if (response.statusCode >= 400) {
                    reject(new ApiError(`Media download failed with HTTP ${response.statusCode}`, response.statusCode));
                    return;
                }

                const appending = response.statusCode === 206 && start > 0;
                const total = Number.parseInt(response.headers['content-length'] || '0', 10) + (appending ? start : 0);
                const stream = fs.createWriteStream(targetFile, appending ? { flags: 'a' } : {});
                let received = appending ? start : 0;

                response.on('data', (chunk) => {
                    received += chunk.length;
                    if (onProgress && total > 0) {
                        onProgress(Math.min(100, Math.round((received / total) * 100)));
                    }
                });

                response.pipe(stream);
                stream.on('finish', () => resolve({ file: targetFile, bytes: received }));
                stream.on('error', reject);
            });

            request.on('error', reject);
            request.end();
        });
    }

    /** Upload a failure screenshot (multipart, hand-rolled to avoid dependencies). */
    uploadScreenshot(jobId, file) {
        return this.multipart(`/worker/jobs/${jobId}/screenshot`, 'screenshot', file);
    }

    uploadThumbnail(mediaId, file) {
        return this.multipart(`/worker/media/${mediaId}/thumbnail`, 'thumbnail', file);
    }

    multipart(route, field, file) {
        return new Promise((resolve, reject) => {
            const boundary = `----linkeasy${Date.now().toString(16)}`;
            const name = path.basename(file);
            const data = fs.readFileSync(file);

            const head = Buffer.from(
                `--${boundary}\r\nContent-Disposition: form-data; name="${field}"; filename="${name}"\r\n`
                + 'Content-Type: application/octet-stream\r\n\r\n',
                'utf8',
            );
            const tail = Buffer.from(`\r\n--${boundary}--\r\n`, 'utf8');
            const body = Buffer.concat([head, data, tail]);

            const url = new URL(`${this.baseUrl}${route}`);
            const transport = url.protocol === 'https:' ? https : http;

            const request = transport.request({
                method: 'POST',
                hostname: url.hostname,
                port: url.port || (url.protocol === 'https:' ? 443 : 80),
                path: url.pathname,
                headers: this.headers({
                    'Content-Type': `multipart/form-data; boundary=${boundary}`,
                    'Content-Length': String(body.length),
                }),
                timeout: 120000,
                rejectUnauthorized: settings.get('server.verifyTls', true) !== false,
            }, (response) => {
                const chunks = [];
                response.on('data', (chunk) => chunks.push(chunk));
                response.on('end', () => {
                    const raw = Buffer.concat(chunks).toString('utf8');
                    let parsed = null;
                    try { parsed = JSON.parse(raw); } catch { parsed = { raw }; }
                    if (response.statusCode >= 200 && response.statusCode < 300) {
                        resolve(parsed);
                    } else {
                        reject(new ApiError(parsed.error || `HTTP ${response.statusCode}`, response.statusCode, parsed));
                    }
                });
            });

            request.on('error', reject);
            request.write(body);
            request.end();
        });
    }
}

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

module.exports = { ApiClient, ApiError, sleep };
