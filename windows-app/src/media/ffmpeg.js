'use strict';

/**
 * Media inspection using the FFmpeg that ships inside the application.
 *
 * Nothing here requires the operator to install FFmpeg: the binary is resolved
 * from the bundled runtime, and if it is missing the dependency manager can
 * repair it (prompt §15, §74).
 */

const fs = require('fs');
const path = require('path');
const { execFile } = require('child_process');
const paths = require('../config/paths');
const { Logger } = require('../logging/logger');

const logger = new Logger({ channel: 'media' });

function ffprobePath() {
    const candidates = [
        paths.ffprobeBinary(),
        path.join(paths.appRoot(), 'runtime', 'ffmpeg', 'bin', 'ffprobe'),
        'ffprobe',
    ];
    return candidates.find((candidate) => candidate === 'ffprobe' || fs.existsSync(candidate)) || 'ffprobe';
}

function ffmpegPath() {
    const candidates = [
        paths.ffmpegBinary(),
        path.join(paths.appRoot(), 'runtime', 'ffmpeg', 'bin', 'ffmpeg'),
        'ffmpeg',
    ];
    return candidates.find((candidate) => candidate === 'ffmpeg' || fs.existsSync(candidate)) || 'ffmpeg';
}

function run(binary, args, timeoutMs = 120000) {
    return new Promise((resolve) => {
        execFile(binary, args, { timeout: timeoutMs, windowsHide: true, maxBuffer: 8 * 1024 * 1024 },
            (error, stdout, stderr) => resolve({ error, stdout: String(stdout), stderr: String(stderr) }));
    });
}

async function version(binary = 'ffmpeg') {
    const result = await run(binary === 'ffmpeg' ? ffmpegPath() : ffprobePath(), ['-version'], 15000);
    return result.error ? null : result.stdout.split('\n')[0].trim();
}

/**
 * Probe a file and return the metadata the dashboard displays.
 * @returns {Promise<{ok:boolean,width:?number,height:?number,duration_s:?number,fps:?number,
 *                    codec:?string,aspect_ratio:?string,size_bytes:number,error:?string}>}
 */
async function probe(file) {
    if (!fs.existsSync(file)) {
        return { ok: false, error: 'The file does not exist.', size_bytes: 0 };
    }

    const size = fs.statSync(file).size;
    const result = await run(ffprobePath(), [
        '-v', 'quiet', '-print_format', 'json', '-show_format', '-show_streams', file,
    ]);

    if (result.error && !result.stdout) {
        return { ok: false, error: `FFprobe failed: ${result.error.message}`, size_bytes: size };
    }

    let data;
    try {
        data = JSON.parse(result.stdout);
    } catch {
        return { ok: false, error: 'FFprobe returned output that could not be parsed.', size_bytes: size };
    }

    const stream = (data.streams || []).find((s) => s.codec_type === 'video');
    if (!stream) {
        return { ok: false, error: 'No video stream found in this file.', size_bytes: size };
    }

    let fps = null;
    if (stream.avg_frame_rate && stream.avg_frame_rate.includes('/')) {
        const [num, den] = stream.avg_frame_rate.split('/').map(Number);
        if (den > 0) {
            fps = Math.round((num / den) * 1000) / 1000;
        }
    }

    const width = stream.width || null;
    const height = stream.height || null;

    return {
        ok: true,
        width,
        height,
        duration_s: data.format && data.format.duration ? Number.parseFloat(data.format.duration) : null,
        fps,
        codec: stream.codec_name || null,
        aspect_ratio: width && height ? aspectRatio(width, height) : null,
        size_bytes: size,
        error: null,
    };
}

/** Generate a poster frame for a video (prompt §15). */
async function thumbnail(file, outputFile, atSeconds = 1) {
    fs.mkdirSync(path.dirname(outputFile), { recursive: true });

    const result = await run(ffmpegPath(), [
        '-y', '-ss', String(atSeconds), '-i', file,
        '-frames:v', '1', '-vf', 'scale=640:-2', '-q:v', '3', outputFile,
    ], 90000);

    if (!fs.existsSync(outputFile) || fs.statSync(outputFile).size === 0) {
        logger.warn('Thumbnail generation failed', { file: path.basename(file), error: result.stderr.slice(-300) });
        return null;
    }

    return outputFile;
}

/**
 * Validate a file against what Facebook's composer accepts, before we spend
 * time uploading it (prompt §15).
 */
async function validateForPublishing(file, kind) {
    const info = await probe(file);
    const problems = [];

    if (!info.ok) {
        return { ok: false, problems: [info.error], info };
    }

    if (kind === 'VIDEO') {
        const duration = info.duration_s || 0;
        if (duration <= 0) {
            problems.push('The video has no measurable duration.');
        }
        if (duration > 4 * 60 * 60) {
            problems.push('Videos longer than four hours are not accepted by Facebook.');
        }
        if (!info.codec || !['h264', 'hevc', 'vp9', 'av1'].includes(info.codec)) {
            problems.push(`The video codec (${info.codec || 'unknown'}) is unlikely to be accepted; H.264 is safest.`);
        }
        if (info.width && info.width < 320) {
            problems.push('The video is narrower than 320 px.');
        }
    }

    if (kind === 'IMAGE') {
        if (!info.width || !info.height) {
            problems.push('The image dimensions could not be read.');
        }
    }

    return { ok: problems.length === 0, problems, info };
}

function aspectRatio(width, height) {
    const gcd = (a, b) => (b === 0 ? a : gcd(b, a % b));
    const divisor = gcd(width, height) || 1;
    const w = Math.round(width / divisor);
    const h = Math.round(height / divisor);
    return (w > 30 || h > 30) ? `${(width / height).toFixed(3)}:1` : `${w}:${h}`;
}

module.exports = { probe, thumbnail, validateForPublishing, version, aspectRatio, ffmpegPath, ffprobePath };
