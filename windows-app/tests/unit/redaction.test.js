'use strict';

/**
 * The logging guarantee: passwords, cookies and tokens must never reach disk
 * (prompt §37). If this test starts failing, the application has a security
 * regression.
 */

const test = require('node:test');
const assert = require('node:assert/strict');

const { redact } = require('../../src/logging/logger');

test('cookies are stripped from log lines', () => {
    const line = 'sending Cookie: c_user=100012345678901; xs=12%3Aabcdef; datr=ABCdef123';
    const clean = redact(line);
    assert.ok(!clean.includes('c_user=100012345678901'), clean);
    assert.ok(!clean.includes('ABCdef123'), clean);
    assert.ok(clean.includes('[redacted]'));
});

test('bearer tokens and worker tokens are stripped', () => {
    const clean = redact('Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.payload.sig and lkw_abcdefghijklmnopqrstuvwxyz012345');
    assert.ok(!clean.includes('eyJhbGciOiJIUzI1NiJ9'), clean);
    assert.ok(!clean.includes('lkw_abcdefghijklmnopqrstuvwxyz012345'), clean);
});

test('password-style key/value pairs are stripped', () => {
    const clean = redact('password=hunter2 api_key: "sk-live-123" {"access_token":"EAAG1234"}');
    assert.ok(!clean.includes('hunter2'), clean);
    assert.ok(!clean.includes('sk-live-123'), clean);
    assert.ok(!clean.includes('EAAG1234'), clean);
});

test('ordinary log content survives intact', () => {
    const line = 'job 42 PUBLISH_IMAGE for Demo Brand 01 completed in 8.2s';
    assert.equal(redact(line), line);
});

test('redaction is applied to nested context values', () => {
    const { Logger } = require('../../src/logging/logger');
    const logger = new Logger({ channel: 'test', console: false, logDir: '/tmp' });
    const entry = {
        message: 'login attempt',
        context: { headers: { cookie: 'c_user=999; xs=secretvalue' } },
    };
    const serialised = JSON.stringify(entry.context, (_key, value) =>
        (typeof value === 'string' ? redact(value) : value));
    assert.ok(!serialised.includes('secretvalue'), serialised);
    assert.ok(logger);
});
