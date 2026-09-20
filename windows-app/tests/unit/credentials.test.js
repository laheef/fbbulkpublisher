'use strict';

/**
 * Credential storage rules (prompt §5, §35):
 *   - the worker token round-trips through secure storage
 *   - it is never written in plaintext
 *   - clearing it removes the file completely
 *   - Facebook passwords are not part of the stored payload at all
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const os = require('os');
const path = require('path');

process.env.LINKEASY_DATA_DIR = fs.mkdtempSync(path.join(os.tmpdir(), 'linkeasy-creds-'));

const credentials = require('../../src/security/credential-store');
const paths = require('../../src/config/paths');

test('the protection level is reported honestly', () => {
    const level = credentials.protectionLevel();
    assert.ok(['dpapi', 'aes-gcm-local'].includes(level), level);
});

test('credentials round-trip and never touch the disk in plaintext', () => {
    credentials.saveWorkerCredentials({
        token: 'lkw_supersecretworkertoken0123456789',
        workerId: '12',
        installationId: 'inst-abc',
        serverUrl: 'https://publisher.example.com',
    });

    const file = paths.credentialFile();
    assert.ok(fs.existsSync(file), 'the credential file should exist');

    const raw = fs.readFileSync(file);
    assert.equal(raw.includes(Buffer.from('lkw_supersecretworkertoken0123456789')), false,
        'the token must not be stored in plaintext');
    assert.equal(raw.includes(Buffer.from('publisher.example.com')), false,
        'the workspace URL must not be stored in plaintext');

    const loaded = credentials.loadWorkerCredentials();
    assert.equal(loaded.token, 'lkw_supersecretworkertoken0123456789');
    assert.equal(loaded.workerId, '12');
    assert.equal(loaded.serverUrl, 'https://publisher.example.com');
});

test('no Facebook password material is ever stored', () => {
    credentials.saveWorkerCredentials({
        token: 'lkw_anothertoken',
        workerId: '13',
        installationId: 'inst-def',
        serverUrl: 'https://publisher.example.com',
    });

    const loaded = credentials.loadWorkerCredentials();
    const serialised = JSON.stringify(loaded).toLowerCase();
    for (const forbidden of ['password', 'passwd', 'fb_pass', 'facebook_password']) {
        assert.equal(serialised.includes(forbidden), false, `stored payload must not contain "${forbidden}"`);
    }
    assert.deepEqual(Object.keys(loaded).sort(), ['installationId', 'kind', 'savedAt', 'serverUrl', 'token', 'version', 'workerId']);
});

test('clearing removes the stored credentials', () => {
    credentials.saveWorkerCredentials({ token: 'lkw_tobecleared', workerId: '14', installationId: 'x', serverUrl: 'https://x.example' });
    credentials.clear();

    assert.equal(fs.existsSync(paths.credentialFile()), false);
    assert.equal(credentials.loadWorkerCredentials(), null);
});
