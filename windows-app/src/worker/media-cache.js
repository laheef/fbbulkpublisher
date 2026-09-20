'use strict';

/**
 * Local media cache.
 *
 * The PHP control plane keeps the master copy of every upload. The worker only
 * downloads what it is about to publish, with resume support, and removes it
 * afterwards unless the operator asked to keep media (prompt §16, §53).
 *
 * The cache is bounded by MEDIA_CACHE_MAX_BYTES and pruned oldest-first, so a
 * machine that has been running for months cannot fill the disk.
 */

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const paths = require('../config/paths');
const settings = require('../config/settings');
const { Logger } = require('../logging/logger');

const log = new Logger({ channel: 'media' });

function cacheFileFor(job, media) {
    const extension = extensionFor(media);
    const safeName = `${job.id}-${media.id}${extension}`;
    return path.join(paths.cache(), safeName);
}

function extensionFor(media) {
    const fromName = media && media.original_name ? path.extname(media.original_name) : '';
    if (fromName) {
        return fromName.toLowerCase().slice(0, 10);
    }
    const byMime = {
        'image/jpeg': '.jpg',
        'image/png': '.png',
        'image/gif': '.gif',
        'image/webp': '.webp',
        'video/mp4': '.mp4',
        'video/quicktime': '.mov',
        'video/x-matroska': '.mkv',
    };
    return (media && byMime[media.mime_type]) || '.bin';
}

/**
 * Ensure the job's media exists locally.
 * @returns {Promise<{ok:boolean, file?:string, code?:string, message?:string, bytes?:number}>}
 */
async function ensureLocalMedia(job, api, { onProgress } = {}) {
    const media = job.media;
    if (!media) {
        return { ok: true, file: null };
    }

    const strategy = settings.get('media.strategy', 'SERVER');

    // The strategy decides whether Facebook gets the file from us directly or
    // from a URL the server exposes. Both are downloaded to disk first so the
    // browser streams from a local file and nothing is buffered in memory.
    const target = cacheFileFor(job, media);

    if (fs.existsSync(target)) {
        const size = fs.statSync(target).size;
        if (!media.size_bytes || size === Number(media.size_bytes)) {
            return { ok: true, file: target, bytes: size, cached: true };
        }
        log.warn('Cached media size mismatch; downloading again.', { job: job.id, expected: media.size_bytes, found: size });
        fs.rmSync(target, { force: true });
    }

    try {
        const result = await api.downloadMedia(job.id, target, onProgress);

        if (media.size_bytes && Number(media.size_bytes) !== result.bytes) {
            log.warn('Downloaded media size differs from the server record.', {
                job: job.id,
                expected: media.size_bytes,
                received: result.bytes,
            });
            return {
                ok: false,
                code: 'MEDIA_DOWNLOAD_FAILED',
                message: 'The media download was incomplete. Nothing was published; the job will be retried.',
            };
        }

        if (media.checksum_sha256) {
            const digest = await sha256(target);
            if (digest !== media.checksum_sha256) {
                fs.rmSync(target, { force: true });
                return {
                    ok: false,
                    code: 'MEDIA_DOWNLOAD_FAILED',
                    message: 'The downloaded media did not match the checksum recorded by the server.',
                };
            }
        }

        log.info('Media ready locally', { job: job.id, bytes: result.bytes, strategy });
        return { ok: true, file: target, bytes: result.bytes };
    } catch (error) {
        return { ok: false, code: 'MEDIA_DOWNLOAD_FAILED', message: `The media could not be downloaded: ${error.message}` };
    }
}

function sha256(file) {
    return new Promise((resolve, reject) => {
        const hash = crypto.createHash('sha256');
        const stream = fs.createReadStream(file);
        stream.on('error', reject);
        stream.on('data', (chunk) => hash.update(chunk));
        stream.on('end', () => resolve(hash.digest('hex')));
    });
}

/** Remove a cached file after a job reaches a terminal state. */
function release(file, { keep = false } = {}) {
    if (!file || keep) {
        return;
    }
    try {
        if (fs.existsSync(file)) {
            fs.rmSync(file, { force: true });
        }
    } catch {
        /* the pruner will get it */
    }
}

/**
 * Keep the cache within its byte budget, oldest first.
 * Files touched in the last hour are left alone so an in-flight job cannot lose
 * its media underneath it.
 */
function prune({ maxBytes = settings.get('media.maxLocalCacheBytes', 5 * 1024 * 1024 * 1024) } = {}) {
    const dir = paths.cache();
    if (!fs.existsSync(dir)) {
        return { removed: 0, bytes: 0 };
    }

    const files = fs.readdirSync(dir)
        .map((name) => {
            const file = path.join(dir, name);
            try {
                const stat = fs.statSync(file);
                return { file, size: stat.size, mtime: stat.mtimeMs };
            } catch {
                return null;
            }
        })
        .filter(Boolean)
        .sort((a, b) => a.mtime - b.mtime);

    const total = files.reduce((sum, item) => sum + item.size, 0);
    if (total <= maxBytes) {
        return { removed: 0, bytes: total };
    }

    let removed = 0;
    let freed = 0;
    let remaining = total;

    for (const item of files) {
        if (remaining <= maxBytes) {
            break;
        }
        if (Date.now() - item.mtime < 60 * 60 * 1000) {
            continue;
        }
        try {
            fs.rmSync(item.file, { force: true });
            removed += 1;
            freed += item.size;
            remaining -= item.size;
        } catch {
            /* locked file; try again next pass */
        }
    }

    if (removed > 0) {
        log.info('Pruned the local media cache', { removed, freedBytes: freed, remainingBytes: remaining });
    }

    return { removed, bytes: remaining, freed };
}

/** Remove stale files that belong to jobs which finished long ago. */
function pruneStale({ olderThanHours = 72 } = {}) {
    const dir = paths.cache();
    if (!fs.existsSync(dir)) {
        return 0;
    }
    const cutoff = Date.now() - olderThanHours * 3600 * 1000;
    let removed = 0;
    for (const name of fs.readdirSync(dir)) {
        const file = path.join(dir, name);
        try {
            if (fs.statSync(file).mtimeMs < cutoff) {
                fs.rmSync(file, { force: true });
                removed += 1;
            }
        } catch {
            /* ignore */
        }
    }
    return removed;
}

module.exports = { ensureLocalMedia, release, prune, pruneStale, cacheFileFor, sha256 };
