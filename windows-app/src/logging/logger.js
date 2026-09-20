'use strict';

/**
 * Rotating file logger for the desktop app and worker.
 *
 * Channels match the server-side mirror: app, worker, browser, scheduler, errors.
 * Every message passes through redact() so credentials, cookies and tokens can
 * never reach disk — the same guarantee the PHP logger gives (prompt §37).
 */

const fs = require('fs');
const path = require('path');
const paths = require('../config/paths');

const LEVELS = { debug: 10, info: 20, warn: 30, error: 40 };
const MAX_BYTES = 5 * 1024 * 1024;
const KEEP = 5;

const SECRET_PATTERNS = [
    [/\b(c_user|xs|fr|datr|sb|presence)=[^\s;]+/gi, '[redacted]'],
    [/\bBearer\s+[A-Za-z0-9\-._~+/]+=*/gi, 'Bearer [redacted]'],
    [/\blkw_[A-Za-z0-9\-_]{16,}/g, 'lkw_[redacted]'],
    [/\b(password|passwd|pwd|secret|token|api[_-]?key|client[_-]?secret)\b\s*[:=]\s*("[^"]*"|'[^']*'|\S+)/gi, '$1=[redacted]'],
    [/"?(access_token|id_token|refresh_token)"?\s*:\s*"[^"]*"/gi, '"token":"[redacted]"'],
];

function redact(message) {
    let out = String(message);
    for (const [pattern, replacement] of SECRET_PATTERNS) {
        out = out.replace(pattern, replacement);
    }
    return out;
}

function rotate(file) {
    try {
        if (!fs.existsSync(file) || fs.statSync(file).size < MAX_BYTES) {
            return;
        }
        for (let i = KEEP - 1; i >= 1; i -= 1) {
            const from = `${file}.${i}`;
            if (fs.existsSync(from)) {
                fs.renameSync(from, `${file}.${i + 1}`);
            }
        }
        fs.renameSync(file, `${file}.1`);
    } catch {
        /* rotation is best-effort; logging must never crash the worker */
    }
}

class Logger {
    constructor(options = {}) {
        this.channel = options.channel || 'app';
        this.minLevel = LEVELS[options.level || 'info'] || LEVELS.info;
        this.alsoConsole = options.console !== false;
        this.logDir = options.logDir || paths.logs();
        this.buffer = [];
        this.mirror = typeof options.mirror === 'function' ? options.mirror : null;
    }

    child(channel) {
        return new Logger({
            channel,
            level: Object.keys(LEVELS).find((k) => LEVELS[k] === this.minLevel),
            console: this.alsoConsole,
            logDir: this.logDir,
            mirror: this.mirror,
        });
    }

    setLevel(level) {
        this.minLevel = LEVELS[level] || LEVELS.info;
    }

    setMirror(fn) {
        this.mirror = fn;
    }

    debug(message, context) { this.write('debug', message, context); }
    info(message, context) { this.write('info', message, context); }
    warn(message, context) { this.write('warn', message, context); }
    error(message, context) { this.write('error', message, context); }

    write(level, message, context) {
        if ((LEVELS[level] || 20) < this.minLevel) {
            return;
        }

        const entry = {
            ts: new Date().toISOString(),
            level,
            channel: this.channel,
            message: redact(message),
            context: context ? JSON.parse(JSON.stringify(context, (key, value) =>
                (typeof value === 'string' ? redact(value) : value))) : undefined,
        };

        const line = JSON.stringify(entry);

        if (this.alsoConsole) {
            const tag = `[${entry.channel}]`;
            const fn = level === 'error' ? console.error : (level === 'warn' ? console.warn : console.log);
            fn(`${tag} ${entry.message}`);
        }

        const file = path.join(this.logDir, entry.level === 'error' || this.channel === 'errors'
            ? 'errors.log'
            : `${this.channel}.log`);

        try {
            rotate(file);
            fs.appendFileSync(file, `${line}\n`);
        } catch {
            /* disk full / permissions: keep running */
        }

        // Queue for the next heartbeat so the dashboard can show the tail.
        this.buffer.push({ channel: entry.channel, level: entry.level, message: entry.message, context: entry.context });
        if (this.buffer.length > 300) {
            this.buffer.splice(0, this.buffer.length - 300);
        }

        if (this.mirror) {
            try {
                this.mirror(entry);
            } catch {
                /* mirroring is optional */
            }
        }
    }

    /** Drain buffered lines for POST /worker/logs. */
    drain(limit = 100) {
        return this.buffer.splice(0, limit);
    }

    readChannel(channel, limit = 300) {
        const file = path.join(this.logDir, `${channel}.log`);
        if (!fs.existsSync(file)) {
            return [];
        }
        const lines = fs.readFileSync(file, 'utf8').trim().split('\n').slice(-limit);
        return lines.map((line) => {
            try { return JSON.parse(line); } catch { return { message: line, level: 'info', channel }; }
        });
    }
}

module.exports = { Logger, redact, LEVELS };
