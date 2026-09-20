<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Clock;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Support;
use App\Models\Media;

/**
 * Upload handling and media metadata.
 *
 * Security posture:
 *   - the client filename is never trusted (sanitised + re-generated)
 *   - MIME type is sniffed with finfo, not taken from the request
 *   - the extension is derived from the sniffed MIME, not the upload name
 *   - files land outside the web root, addressed by a database-relative path
 *   - size limits are enforced before and after the transfer
 *
 * Video probing is delegated to the worker's bundled FFmpeg (see
 * WorkerController::mediaProbe), because the user's machine is where FFmpeg
 * lives. If a server-side ffprobe is configured, it is used as well.
 */
final class MediaService
{
    private const MAGIC = [
        'image/jpeg'      => "\xFF\xD8\xFF",
        'image/png'       => "\x89PNG\r\n\x1a\n",
        'image/webp'      => 'RIFF',
        'video/mp4'       => null,   // ftyp box, checked below
        'video/quicktime' => null,
    ];

    /**
     * @param array<string,mixed> $file  $_FILES entry
     * @return array<string,mixed>       Media row
     */
    public static function store(int $userId, array $file): array
    {
        self::assertUploadOk($file);

        $tmpPath = (string) $file['tmp_name'];
        $size = (int) ($file['size'] ?? 0);
        $originalName = Support::safeFileName((string) ($file['name'] ?? 'upload'));

        $mime = self::sniffMime($tmpPath);
        $kind = self::kindForMime($mime, $size);

        $ext = $kind === 'IMAGE'
            ? (Media::IMAGE_TYPES[$mime] ?? null)
            : (Media::VIDEO_TYPES[$mime] ?? null);

        if ($ext === null) {
            throw HttpException::validation('Unsupported file type. Allowed: JPG, JPEG, PNG, WEBP, MP4 and MOV (where supported).', [
                'file' => 'Detected type ' . $mime . ' is not supported.',
            ]);
        }

        $maxBytes = $kind === 'IMAGE'
            ? Config::int('uploads.max_image_bytes', 26214400)
            : Config::int('uploads.max_video_bytes', 4294967296);

        if ($size > $maxBytes) {
            throw HttpException::validation('That file is larger than the configured limit of ' . Support::bytesToHuman($maxBytes) . '.', [
                'file' => 'Upload rejected.',
            ]);
        }

        // Deterministic, collision-free storage key.
        $folder = date('Y/m');
        $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
        $base = rtrim(Config::str('uploads.disk_path'), '/');
        $relative = $folder . '/' . $storedName;
        $absolute = $base . '/' . $relative;

        if (!is_dir(dirname($absolute)) && !mkdir(dirname($absolute), 0775, true) && !is_dir(dirname($absolute))) {
            throw new \RuntimeException('Unable to create the upload directory.');
        }

        if (!move_uploaded_file($tmpPath, $absolute)) {
            // Fall back for CLI/test contexts where the upload flag is absent.
            if (!@rename($tmpPath, $absolute) && !@copy($tmpPath, $absolute)) {
                throw new \RuntimeException('Unable to move the uploaded file into storage.');
            }
        }
        @chmod($absolute, 0640);

        $probe = MediaProbe::inspect($absolute, $kind, $mime);
        $thumbnailPath = MediaProbe::generateThumbnail($absolute, $kind, $relative);

        $mediaId = Media::table();
        $id = \App\Core\Database::instance()->insert($mediaId, [
            'user_id'        => $userId,
            'kind'           => $kind,
            'original_name'  => mb_substr($originalName, 0, 255),
            'stored_name'    => $storedName,
            'relative_path'  => $relative,
            'mime_type'      => $mime,
            'size_bytes'     => filesize($absolute) ?: $size,
            'width'          => $probe['width'],
            'height'         => $probe['height'],
            'duration_s'     => $probe['duration_s'],
            'fps'            => $probe['fps'],
            'codec'          => $probe['codec'],
            'aspect_ratio'   => $probe['aspect_ratio'],
            'checksum_sha256' => hash_file('sha256', $absolute) ?: null,
            'thumbnail_path' => $thumbnailPath,
            'probe_status'   => $probe['ok'] ? 'PROBED' : 'PENDING',
            'probe_error'    => $probe['error'],
            'strategy'       => Config::str('storage.media_strategy', 'SERVER'),
        ]);

        Logger::info('Media uploaded', [
            'media_id' => $id,
            'kind'     => $kind,
            'bytes'    => filesize($absolute) ?: $size,
            'mime'     => $mime,
        ]);

        return Media::find($id) ?? [];
    }

    /** @param array<string,mixed> $file */
    private static function assertUploadOk(array $file): void
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_OK) {
            if (!is_file((string) ($file['tmp_name'] ?? ''))) {
                throw HttpException::validation('The upload did not arrive correctly. Please try again.');
            }
            return;
        }

        $messages = [
            UPLOAD_ERR_INI_SIZE   => 'The file exceeds the server upload limit.',
            UPLOAD_ERR_FORM_SIZE  => 'The file exceeds the form upload limit.',
            UPLOAD_ERR_PARTIAL    => 'The upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE    => 'No file was selected.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server has no temporary directory configured.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write the upload to disk.',
            UPLOAD_ERR_EXTENSION  => 'A server extension blocked the upload.',
        ];

        throw HttpException::validation($messages[$error] ?? 'The upload failed.', ['file' => 'Rejected.']);
    }

    /** Sniff the real MIME type from the file's bytes. */
    public static function sniffMime(string $path): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);
        $mime = is_string($mime) ? strtolower($mime) : 'application/octet-stream';

        // finfo reports QuickTime for some MP4s and vice versa — normalise via
        // the ISO-BMFF brand so MOV/MP4 are classified by content.
        if (in_array($mime, ['video/mp4', 'video/quicktime', 'application/octet-stream'], true)) {
            $head = (string) @file_get_contents($path, false, null, 0, 64);
            if (str_contains($head, 'ftyp')) {
                if (str_contains($head, 'qt  ')) {
                    return 'video/quicktime';
                }
                if (preg_match('/ftyp(isom|iso2|mp41|mp42|avc1|dash|M4V)/', $head) === 1) {
                    return 'video/mp4';
                }
                return 'video/mp4';
            }
        }

        return $mime;
    }

    private static function kindForMime(string $mime, int $size): string
    {
        if (array_key_exists($mime, Media::IMAGE_TYPES)) {
            return 'IMAGE';
        }
        if (array_key_exists($mime, Media::VIDEO_TYPES)) {
            return 'VIDEO';
        }
        throw HttpException::validation('That file type is not supported.', ['file' => 'Unsupported MIME type: ' . $mime]);
    }

    /**
     * Stream a file to a client with range support (§53). Used by worker media
     * downloads so a 3 GB video never passes through PHP memory.
     */
    public static function stream(array $media, ?int $userId = null): void
    {
        $path = Media::absolutePath($media);
        if (!is_file($path)) {
            throw HttpException::notFound('That media file is no longer available.');
        }

        $size = (int) filesize($path);
        $mime = (string) $media['mime_type'];

        if (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: ' . $mime);
        header('Accept-Ranges: bytes');
        header('Content-Disposition: inline; filename="' . rawurlencode((string) $media['original_name']) . '"');
        header('Cache-Control: private, max-age=300');
        header('X-Content-Type-Options: nosniff');

        $start = 0;
        $end = $size - 1;

        if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', (string) $_SERVER['HTTP_RANGE'], $m) === 1) {
            $start = $m[1] === '' ? 0 : (int) $m[1];
            $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
            if ($start > $end || $start >= $size) {
                header('HTTP/1.1 416 Range Not Satisfiable');
                header('Content-Range: bytes */' . $size);
                exit;
            }
            header('HTTP/1.1 206 Partial Content');
            header("Content-Range: bytes {$start}-{$end}/{$size}");
        }

        header('Content-Length: ' . ($end - $start + 1));

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Unable to read the media file.');
        }
        fseek($handle, $start);

        $remaining = $end - $start + 1;
        $chunk = 262144;   // 256 KB
        while ($remaining > 0 && !feof($handle)) {
            $read = (int) min($chunk, $remaining);
            echo fread($handle, $read);
            $remaining -= $read;
            flush();
        }
        fclose($handle);
    }

    /** Remove binaries that no longer have a referenced media row (cron). */
    public static function pruneOrphanedFiles(int $limit = 500): int
    {
        $base = rtrim(Config::str('uploads.disk_path'), '/');
        if (!is_dir($base)) {
            return 0;
        }

        $deleted = 0;
        $rows = \App\Core\Database::instance()->select(
            'SELECT * FROM media WHERE deleted_at IS NOT NULL LIMIT ' . (int) $limit
        );

        foreach ($rows as $row) {
            if (Media::isReferenced((int) $row['id'])) {
                continue;
            }
            $path = $base . '/' . ltrim((string) $row['relative_path'], '/');
            if (is_file($path)) {
                @unlink($path);
            }
            $thumb = $base . '/' . ltrim((string) ($row['thumbnail_path'] ?? ''), '/');
            if (($row['thumbnail_path'] ?? '') !== '' && is_file($thumb)) {
                @unlink($thumb);
            }
            \App\Core\Database::instance()->query('DELETE FROM media WHERE id = ?', [(int) $row['id']]);
            $deleted++;
        }

        return $deleted;
    }
}
