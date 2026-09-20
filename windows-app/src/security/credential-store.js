'use strict';

/**
 * Secure local storage for the worker token.
 *
 * On Windows the token is encrypted with DPAPI (per-user scope) through
 * Electron's safeStorage, which means another Windows user on the same machine
 * cannot decrypt it, and the file is useless if copied to another PC.
 *
 * On non-Windows development machines (and in CI) it falls back to AES-256-GCM
 * with a key derived from the machine id, and clearly reports that the
 * protection level is weaker.
 *
 * Facebook passwords are NEVER stored: authentication happens in the browser,
 * in a persistent profile, exactly as a human would sign in.
 */

const fs = require('fs');
const os = require('os');
const path = require('path');
const crypto = require('crypto');
const paths = require('../config/paths');

let electronSafeStorage = null;
try {
    // Present only when running inside Electron.
    ({ safeStorage: electronSafeStorage } = require('electron'));
} catch {
    electronSafeStorage = null;
}

function machineKey() {
    const material = [os.hostname(), os.platform(), os.arch(), os.userInfo().username, 'linkeasy'].join('|');
    return crypto.createHash('sha256').update(material).digest();
}

function protectionLevel() {
    if (electronSafeStorage && typeof electronSafeStorage.isEncryptionAvailable === 'function'
        && electronSafeStorage.isEncryptionAvailable()) {
        return 'dpapi';
    }
    return 'aes-gcm-local';
}

function write(payload) {
    const file = paths.credentialFile();
    fs.mkdirSync(path.dirname(file), { recursive: true });

    const json = Buffer.from(JSON.stringify(payload), 'utf8');

    if (protectionLevel() === 'dpapi') {
        const encrypted = electronSafeStorage.encryptString(JSON.stringify(payload));
        fs.writeFileSync(file, Buffer.concat([Buffer.from('DPAPI1\0'), encrypted]), { mode: 0o600 });
    } else {
        const iv = crypto.randomBytes(12);
        const cipher = crypto.createCipheriv('aes-256-gcm', machineKey(), iv);
        const ciphertext = Buffer.concat([cipher.update(json), cipher.final()]);
        const tag = cipher.getAuthTag();
        fs.writeFileSync(file, Buffer.concat([Buffer.from('AESGCM1\0'), iv, tag, ciphertext]), { mode: 0o600 });
    }

    try {
        fs.chmodSync(file, 0o600);
    } catch { /* Windows ACLs already restrict this to the current user */ }
}

function read() {
    const file = paths.credentialFile();
    if (!fs.existsSync(file)) {
        return null;
    }

    const raw = fs.readFileSync(file);
    const magic = raw.subarray(0, 8).toString('utf8');

    try {
        if (magic.startsWith('DPAPI1')) {
            if (!electronSafeStorage) {
                return null;
            }
            return JSON.parse(electronSafeStorage.decryptString(raw.subarray(8)));
        }
        if (magic.startsWith('AESGCM1')) {
            const iv = raw.subarray(8, 20);
            const tag = raw.subarray(20, 36);
            const ciphertext = raw.subarray(36);
            const decipher = crypto.createDecipheriv('aes-256-gcm', machineKey(), iv);
            decipher.setAuthTag(tag);
            return JSON.parse(Buffer.concat([decipher.update(ciphertext), decipher.final()]).toString('utf8'));
        }
    } catch {
        return null;
    }

    return null;
}

function clear() {
    const file = paths.credentialFile();
    if (fs.existsSync(file)) {
        fs.writeFileSync(file, crypto.randomBytes(32));   // overwrite before unlinking
        fs.unlinkSync(file);
    }
}

/** Store the worker token and installation identity. */
function saveWorkerCredentials({ token, workerId, installationId, serverUrl }) {
    write({
        version: 1,
        kind: 'worker',
        token,
        workerId,
        installationId,
        serverUrl,
        savedAt: new Date().toISOString(),
    });
}

function loadWorkerCredentials() {
    const data = read();
    if (!data || data.kind !== 'worker' || !data.token) {
        return null;
    }
    return data;
}

/** Optional per-profile metadata (never cookies — those stay in the profile). */
function saveProfileMeta(profileRef, meta) {
    const file = path.join(paths.profiles(), `${profileRef}.json`);
    fs.writeFileSync(file, JSON.stringify({ profileRef, ...meta, updatedAt: new Date().toISOString() }, null, 2), { mode: 0o600 });
}

module.exports = {
    saveWorkerCredentials,
    loadWorkerCredentials,
    saveProfileMeta,
    clear,
    protectionLevel,
};
