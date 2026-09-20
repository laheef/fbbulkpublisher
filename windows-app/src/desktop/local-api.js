'use strict';

/**
 * Local dashboard API.
 *
 * Everything the desktop UI shows is served from here, and the server binds to
 * 127.0.0.1 on a random free port only — never 0.0.0.0, never a fixed public
 * port, and never without a bearer token (prompt §30, §52, §76).
 *
 * A random token is generated at startup; the renderer receives it over IPC, so
 * another process on the machine cannot drive the API through a browser tab.
 */

const http = require('http');
const crypto = require('crypto');
const fs = require('fs');

const { Logger } = require('../logging/logger');
const runtime = require('../runtime/dependency-manager');
const settings = require('../config/settings');
const paths = require('../config/paths');

const log = new Logger({ channel: 'app' });
const MAX_BODY = 256 * 1024;

class LocalApi {
    constructor(deps) {
        this.worker = deps.worker;
        this.notifier = deps.notifier;
        this.server = null;
        this.token = crypto.randomBytes(32).toString('hex');
        this.onControl = deps.onControl || (() => {});
    }

    async start() {
        this.server = http.createServer((request, response) => this.handle(request, response));

        await new Promise((resolve, reject) => {
            this.server.once('error', reject);
            // Host is always the loopback interface.
            this.server.listen(settings.get('localApi.port', 0), '127.0.0.1', resolve);
        });

        const { port, address } = this.server.address();
        this.port = port;
        log.info('Local dashboard API listening', { address, port });
        return { port, token: this.token, url: `http://127.0.0.1:${port}/` };
    }

    async stop() {
        if (this.server) {
            await new Promise((resolve) => this.server.close(resolve));
        }
    }

    async handle(request, response) {
        const url = new URL(request.url, `http://127.0.0.1:${this.port}`);

        // No CORS headers at all: nothing outside this machine can call us, and
        // nothing on this machine can do it from a web page.
        response.setHeader('Cache-Control', 'no-store');
        response.setHeader('X-Content-Type-Options', 'nosniff');
        response.setHeader('Referrer-Policy', 'no-referrer');

        if (request.method === 'OPTIONS') {
            response.writeHead(204).end();
            return;
        }

        // Static shell for the window itself (served from the same origin).
        if (url.pathname === '/' || url.pathname === '/index.html') {
            return this.serveFile(response, require('path').join(__dirname, 'renderer', 'index.html'), 'text/html');
        }
        if (url.pathname === '/styles.css') {
            return this.serveFile(response, require('path').join(__dirname, 'renderer', 'styles.css'), 'text/css');
        }
        if (url.pathname === '/app.js') {
            return this.serveFile(response, require('path').join(__dirname, 'renderer', 'app.js'), 'application/javascript');
        }

        if (!this.authorised(request)) {
            return json(response, 401, { ok: false, error: 'Not authorised.' });
        }

        try {
            switch (`${request.method} ${url.pathname}`) {
                case 'GET /api/status':
                    return json(response, 200, { ok: true, data: this.worker ? this.worker.status() : {} });
                case 'GET /api/runtime':
                    return json(response, 200, { ok: true, data: await runtime.report() });
                case 'GET /api/settings':
                    return json(response, 200, { ok: true, data: settings.all() });
                case 'GET /api/logs':
                    return json(response, 200, {
                        ok: true,
                        data: {
                            channel: url.searchParams.get('channel') || 'app',
                            lines: require('../logging/logger').Logger.prototype.readChannel.call(
                                new (require('../logging/logger').Logger)({ channel: 'app', console: false }),
                                url.searchParams.get('channel') || 'app',
                                Number(url.searchParams.get('limit') || 200),
                            ),
                        },
                    });
                case 'GET /api/notifications':
                    return json(response, 200, {
                        ok: true,
                        data: this.notifier.list({ unreadOnly: url.searchParams.get('unread') === '1' }),
                    });
                case 'GET /api/profiles':
                    return json(response, 200, { ok: true, data: listProfiles() });
                case 'POST /api/heartbeat':
                    return this.withBody(request, response, async (body) => {
                        await this.worker.heartbeat();
                        return { ok: true, data: this.worker.status() };
                    });
                case 'POST /api/pause':
                    return this.withBody(request, response, async () => {
                        this.worker.paused = true;
                        this.worker.pausedReason = { code: 'OPERATOR_PAUSED' };
                        this.worker.setState('PAUSED');
                        return { ok: true, data: this.worker.status() };
                    });
                case 'POST /api/resume':
                    return this.withBody(request, response, async () => {
                        this.worker.resume();
                        return { ok: true, data: this.worker.status() };
                    });
                case 'POST /api/updates/check':
                    return this.withBody(request, response, async () => ({ ok: true, data: await this.onControl('check-updates') }));
                case 'POST /api/updates/install':
                    return this.withBody(request, response, async () => ({ ok: true, data: await this.onControl('install-update') }));
                case 'POST /api/runtime/repair':
                    return this.withBody(request, response, async () => {
                        const result = await runtime.repair();
                        return { ok: true, data: { ok: result.after.ok, repaired: result.repaired } };
                    });
                case 'POST /api/notifications/read':
                    return this.withBody(request, response, async () => {
                        this.notifier.markAllRead();
                        return { ok: true };
                    });
                case 'POST /api/settings':
                    return this.withBody(request, response, async (body) => {
                        const applied = this.onControl('settings', body);
                        return { ok: true, data: applied };
                    });
                case 'POST /api/shutdown-worker':
                    return this.withBody(request, response, async () => {
                        await this.worker.stop();
                        return { ok: true };
                    });
                default:
                    return json(response, 404, { ok: false, error: 'Unknown endpoint.' });
            }
        } catch (error) {
            log.error('Local API request failed', { path: url.pathname, error: String(error) });
            return json(response, 500, { ok: false, error: error.message });
        }
    }

    authorised(request) {
        const header = request.headers.authorization || '';
        const token = header.startsWith('Bearer ') ? header.slice(7) : (request.headers['x-linkeasy-token'] || '');
        if (!token) {
            return false;
        }
        const expected = Buffer.from(this.token);
        const received = Buffer.from(String(token));
        return expected.length === received.length && crypto.timingSafeEqual(expected, received);
    }

    withBody(request, response, handler) {
        let size = 0;
        const chunks = [];
        request.on('data', (chunk) => {
            size += chunk.length;
            if (size > MAX_BODY) {
                request.destroy();
                return;
            }
            chunks.push(chunk);
        });
        request.on('end', async () => {
            let body = null;
            if (chunks.length > 0) {
                try {
                    body = JSON.parse(Buffer.concat(chunks).toString('utf8'));
                } catch {
                    return json(response, 400, { ok: false, error: 'Invalid JSON body.' });
                }
            }
            try {
                const result = await handler(body);
                return json(response, 200, result);
            } catch (error) {
                return json(response, 500, { ok: false, error: error.message });
            }
        });
        return undefined;
    }

    serveFile(response, file, contentType) {
        try {
            const data = fs.readFileSync(file);
            response.writeHead(200, { 'Content-Type': `${contentType}; charset=utf-8`, 'Content-Length': data.length });
            response.end(data);
        } catch {
            response.writeHead(404, { 'Content-Type': 'text/plain' });
            response.end('Not found');
        }
    }
}

function listProfiles() {
    const dir = paths.profiles();
    try {
        return fs.readdirSync(dir, { withFileTypes: true })
            .filter((entry) => entry.isDirectory())
            .map((entry) => {
                const full = require('path').join(dir, entry.name);
                const stat = fs.statSync(full);
                let size = 0;
                try {
                    size = directorySize(full);
                } catch {
                    size = 0;
                }
                return {
                    profile: entry.name,
                    modifiedAt: stat.mtime.toISOString(),
                    bytes: size,
                };
            });
    } catch {
        return [];
    }
}

function directorySize(dir) {
    let total = 0;
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = require('path').join(dir, entry.name);
        if (entry.isDirectory()) {
            total += directorySize(full);
        } else {
            try {
                total += fs.statSync(full).size;
            } catch {
                /* skip */
            }
        }
    }
    return total;
}

function json(response, status, payload) {
    const body = Buffer.from(JSON.stringify(payload), 'utf8');
    response.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', 'Content-Length': body.length });
    response.end(body);
}

module.exports = { LocalApi };
