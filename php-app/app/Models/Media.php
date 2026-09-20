<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Clock;
use App\Core\Config;

/**
 * Uploaded media. The binary itself lives on disk (or external storage);
 * this table holds metadata plus the FFmpeg probe result.
 */
final class Media extends Model
{
    protected static string $table = 'media';
    protected static array $fillable = [
        'user_id', 'kind', 'original_name', 'stored_name', 'relative_path', 'mime_type',
        'size_bytes', 'width', 'height', 'duration_s', 'fps', 'codec', 'aspect_ratio',
        'checksum_sha256', 'thumbnail_path', 'probe_status', 'probe_error',
        'strategy', 'external_url', 'deleted_at',
    ];

    public const IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    public const VIDEO_TYPES = ['video/mp4' => 'mp4', 'video/quicktime' => 'mov'];

    /** @return list<array<string,mixed>> */
    public static function forUser(int $userId, int $limit = 100, ?string $kind = null): array
    {
        $sql = 'SELECT * FROM media WHERE user_id = ? AND deleted_at IS NULL';
        $params = [$userId];
        if ($kind !== null && in_array($kind, ['IMAGE', 'VIDEO'], true)) {
            $sql .= ' AND kind = ?';
            $params[] = $kind;
        }
        $sql .= ' ORDER BY created_at DESC LIMIT ' . (int) $limit;
        return static::db()->select($sql, $params);
    }

    public static function absolutePath(array $media): string
    {
        $base = rtrim(Config::str('uploads.disk_path'), '/');
        $relative = (string) ($media['relative_path'] ?? '');
        \App\Core\Support::assertSafeRelativePath($relative);
        return $base . '/' . ltrim($relative, '/');
    }

    /** Absolute path of the generated thumbnail, or null when absent. */
    public static function thumbnailAbsolutePath(array $media): ?string
    {
        $relative = (string) ($media['thumbnail_path'] ?? '');
        if ($relative === '') {
            return null;
        }
        try {
            \App\Core\Support::assertSafeRelativePath($relative);
        } catch (\InvalidArgumentException) {
            return null;
        }
        $path = rtrim(Config::str('uploads.disk_path'), '/') . '/' . ltrim($relative, '/');
        return is_file($path) ? $path : null;
    }

    public static function markProbed(int $mediaId, array $probe): void
    {
        static::db()->update('media', [
            'width'         => $probe['width'] ?? null,
            'height'        => $probe['height'] ?? null,
            'duration_s'    => $probe['duration_s'] ?? null,
            'fps'           => $probe['fps'] ?? null,
            'codec'         => isset($probe['codec']) ? mb_substr((string) $probe['codec'], 0, 64) : null,
            'aspect_ratio'  => $probe['aspect_ratio'] ?? null,
            'thumbnail_path' => $probe['thumbnail_path'] ?? null,
            'probe_status'  => 'PROBED',
            'probe_error'   => null,
        ], ['id' => $mediaId]);
    }

    public static function markProbeFailed(int $mediaId, string $error): void
    {
        static::db()->update('media', [
            'probe_status' => 'FAILED',
            'probe_error'  => mb_substr($error, 0, 500),
        ], ['id' => $mediaId]);
    }

    /** Soft delete + schedule the binary for cleanup by the retention cron. */
    public static function softDelete(int $mediaId): void
    {
        static::db()->update('media', ['deleted_at' => Clock::nowString()], ['id' => $mediaId]);
    }

    /**
     * Referenced check — media still attached to a live job must never be
     * removed from disk (prompt §54).
     */
    public static function isReferenced(int $mediaId): bool
    {
        $referencing = (int) static::db()->scalar(
            "SELECT COUNT(*) FROM jobs WHERE media_id = ? AND status IN ('SCHEDULED','QUEUED','CLAIMED','PROCESSING','UPLOADING','PUBLISHING','VERIFYING','RETRYING','PAUSED','USER_ACTION_REQUIRED')",
            [$mediaId]
        );
        $draft = (int) static::db()->scalar(
            "SELECT COUNT(*) FROM posts WHERE media_id = ? AND status IN ('DRAFT','SCHEDULED','QUEUED')",
            [$mediaId]
        );
        return ($referencing + $draft) > 0;
    }

    /** @return array<string,mixed> */
    public static function summaryForUser(int $userId): array
    {
        $row = static::db()->first(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN kind = \'IMAGE\' THEN 1 ELSE 0 END) AS images,
                    SUM(CASE WHEN kind = \'VIDEO\' THEN 1 ELSE 0 END) AS videos,
                    COALESCE(SUM(size_bytes), 0) AS bytes
             FROM media WHERE user_id = ? AND deleted_at IS NULL',
            [$userId]
        ) ?? [];

        return [
            'total'  => (int) ($row['total'] ?? 0),
            'images' => (int) ($row['images'] ?? 0),
            'videos' => (int) ($row['videos'] ?? 0),
            'bytes'  => (int) ($row['bytes'] ?? 0),
        ];
    }
}
