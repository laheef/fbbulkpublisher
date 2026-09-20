'use strict';

/**
 * Local media cache behaviour: correct file naming, cache reuse, and the size
 * budget that stops a long-running machine from filling its disk (prompt §16).
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const os = require('os');
const path = require('path');

process.env.LINKEASY_DATA_DIR = fs.mkdtempSync(path.join(os.tmpdir(), 'linkeasy-cache-'));

const mediaCache = require('../../src/worker/media-cache');
const paths = require('../../src/config/paths');

test('cache file names keep safe extensions per media type', () => {
    const job = { id: 42 };
    const image = mediaCache.cacheFileFor(job, { id: 7, original_name: 'Launch Photo.PNG', mime_type: 'image/png' });
    assert.ok(image.endsWith('42-7.png'), image);

    const video = mediaCache.cacheFileFor(job, { id: 8, original_name: 'clip', mime_type: 'video/mp4' });
    assert.ok(video.endsWith('42-8.mp4'), video);

    const unknown = mediaCache.cacheFileFor(job, { id: 9, original_name: null, mime_type: 'application/x-thing' });
    assert.ok(unknown.endsWith('42-9.bin'), unknown);
});

test('a cached file that matches the recorded size is reused without downloading', async () => {
    const job = { id: 100, media: { id: 1, size_bytes: 5, original_name: 'a.jpg', mime_type: 'image/jpeg' } };
    const file = mediaCache.cacheFileFor(job, job.media);
    fs.writeFileSync(file, 'hello');

    let called = false;
    const result = await mediaCache.ensureLocalMedia(job, {
        downloadMedia: async () => { called = true; return { bytes: 5 }; },
    });

    assert.equal(result.ok, true);
    assert.equal(result.cached, true);
    assert.equal(called, false, 'a valid cache entry must not trigger a download');
});

test('a truncated download is rejected rather than published', async () => {
    const job = { id: 101, media: { id: 2, size_bytes: 1024, original_name: 'b.mp4', mime_type: 'video/mp4' } };
    const result = await mediaCache.ensureLocalMedia(job, {
        downloadMedia: async (_jobId, target) => {
            fs.writeFileSync(target, 'short');
            return { bytes: 5 };
        },
    });

    assert.equal(result.ok, false);
    assert.equal(result.code, 'MEDIA_DOWNLOAD_FAILED');
});

test('release removes the cached copy unless the operator asked to keep it', () => {
    const file = path.join(paths.cache(), 'release-me.tmp');
    fs.writeFileSync(file, 'x');

    mediaCache.release(file);
    assert.equal(fs.existsSync(file), false);

    fs.writeFileSync(file, 'x');
    mediaCache.release(file, { keep: true });
    assert.equal(fs.existsSync(file), true);
    fs.rmSync(file, { force: true });
});

test('pruning respects the byte budget and leaves recent files alone', () => {
    const dir = paths.cache();
    const old = path.join(dir, 'old.bin');
    const fresh = path.join(dir, 'fresh.bin');

    fs.writeFileSync(old, Buffer.alloc(4096, 1));
    fs.writeFileSync(fresh, Buffer.alloc(4096, 2));

    const past = Date.now() - 24 * 3600 * 1000;
    fs.utimesSync(old, past / 1000, past / 1000);

    const result = mediaCache.prune({ maxBytes: 1024 });

    assert.equal(fs.existsSync(old), false, 'old files are pruned first');
    assert.equal(fs.existsSync(fresh), true, 'just-written files are never removed');
    assert.ok(result.removed >= 1);
    fs.rmSync(fresh, { force: true });
});
