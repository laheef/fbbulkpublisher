<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Clock;
use App\Core\Support;

final class Post extends Model
{
    protected static string $table = 'posts';
    protected static array $fillable = [
        'uuid', 'user_id', 'kind', 'provider', 'caption', 'hashtags', 'link_url', 'title',
        'media_id', 'settings', 'status', 'scheduled_at', 'timezone', 'published_at', 'page_count',
    ];

    public const KINDS = ['TEXT', 'IMAGE', 'VIDEO', 'REEL', 'MIXED'];
    public const STATUSES = [
        'DRAFT', 'SCHEDULED', 'QUEUED', 'CLAIMED', 'PROCESSING', 'UPLOADING', 'PUBLISHING', 'VERIFYING',
        'PUBLISHED', 'PARTIAL', 'FAILED', 'RETRYING', 'PAUSED', 'USER_ACTION_REQUIRED',
        'ACCOUNT_REAUTH_REQUIRED', 'CANCELLED',
    ];

    /** @return array<string,mixed>|null */
    public static function findByUuid(string $uuid): ?array
    {
        return static::db()->first('SELECT * FROM posts WHERE uuid = ? LIMIT 1', [$uuid]);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function compose(int $userId, array $data, string $timezone): array
    {
        $kind = in_array($data['kind'] ?? '', self::KINDS, true) ? (string) $data['kind'] : 'TEXT';

        $id = static::db()->insert('posts', [
            'uuid'      => Support::uuid4(),
            'user_id'   => $userId,
            'kind'      => $kind,
            'provider'  => (string) ($data['provider'] ?? 'BROWSER'),
            'caption'   => (string) ($data['caption'] ?? ''),
            'hashtags'  => isset($data['hashtags']) ? mb_substr((string) $data['hashtags'], 0, 1000) : null,
            'link_url'  => $data['link_url'] ?? null,
            'title'     => isset($data['title']) ? mb_substr((string) $data['title'], 0, 255) : null,
            'media_id'  => $data['media_id'] ?? null,
            'settings'  => json_encode($data['settings'] ?? new \stdClass(), JSON_UNESCAPED_SLASHES),
            'status'    => 'DRAFT',
            'timezone'  => $timezone,
        ]);

        return static::find($id) ?? [];
    }

    /** @param list<int> $pageIds */
    public static function attachPages(int $postId, int $userId, array $pageIds): int
    {
        $attached = 0;
        foreach (array_unique(array_map('intval', $pageIds)) as $pageId) {
            if ($pageId <= 0) {
                continue;
            }
            $exists = (int) static::db()->scalar(
                'SELECT COUNT(*) FROM post_pages WHERE post_id = ? AND page_id = ?',
                [$postId, $pageId]
            );
            if ($exists > 0) {
                continue;
            }
            static::db()->insert('post_pages', [
                'post_id' => $postId,
                'page_id' => $pageId,
                'user_id' => $userId,
                'status'  => 'PENDING',
            ]);
            $attached++;
        }

        static::refreshPageCount($postId);
        return $attached;
    }

    /** @param list<int> $pageIds */
    public static function detachPages(int $postId, array $pageIds): int
    {
        $removed = 0;
        foreach ($pageIds as $pageId) {
            $removed += static::db()->query(
                'DELETE FROM post_pages WHERE post_id = ? AND page_id = ? AND status IN (\'PENDING\',\'QUEUED\')',
                [$postId, (int) $pageId]
            )->rowCount();
        }
        static::refreshPageCount($postId);
        return $removed;
    }

    public static function refreshPageCount(int $postId): void
    {
        $count = (int) static::db()->scalar('SELECT COUNT(*) FROM post_pages WHERE post_id = ?', [$postId]);
        static::db()->update('posts', ['page_count' => $count], ['id' => $postId]);
    }

    public static function setStatus(int $postId, string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            return;
        }
        static::db()->update('posts', ['status' => $status], ['id' => $postId]);
    }

    /**
     * Roll the parent post status up from its per-page children so the UI can
     * show a single truthful state (PUBLISHED / PARTIAL / FAILED / ...).
     */
    public static function rollUpStatus(int $postId): string
    {
        $rows = static::db()->select(
            'SELECT status, COUNT(*) AS c FROM post_pages WHERE post_id = ? GROUP BY status',
            [$postId]
        );
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['c'];
        }

        $total = array_sum($counts);
        if ($total === 0) {
            return 'DRAFT';
        }

        $published = $counts['PUBLISHED'] ?? 0;
        $failed = ($counts['FAILED'] ?? 0) + ($counts['CANCELLED'] ?? 0);
        $action = ($counts['USER_ACTION_REQUIRED'] ?? 0) + ($counts['ACCOUNT_REAUTH_REQUIRED'] ?? 0);
        $active = ($counts['QUEUED'] ?? 0) + ($counts['CLAIMED'] ?? 0) + ($counts['PROCESSING'] ?? 0)
            + ($counts['UPLOADING'] ?? 0) + ($counts['PUBLISHING'] ?? 0) + ($counts['VERIFYING'] ?? 0)
            + ($counts['RETRYING'] ?? 0) + ($counts['SCHEDULED'] ?? 0);

        if ($published === $total) {
            $status = 'PUBLISHED';
        } elseif ($action > 0 && $active === 0) {
            $status = 'USER_ACTION_REQUIRED';
        } elseif ($published > 0 && ($failed > 0 || $action > 0)) {
            $status = 'PARTIAL';
        } elseif ($failed === $total && $total > 0) {
            $status = 'FAILED';
        } elseif ($active > 0) {
            $status = $published > 0 ? 'PUBLISHING' : 'QUEUED';
        } else {
            $status = 'FAILED';
        }

        static::db()->update('posts', [
            'status'       => $status,
            'published_at' => $published > 0 ? Clock::nowString() : null,
        ], ['id' => $postId]);

        return $status;
    }

    /** @return array<string,mixed>|null Post joined with media + per-page rows. */
    public static function detail(int $postId): ?array
    {
        $post = static::db()->first(
            'SELECT p.*, m.kind AS media_kind, m.original_name, m.thumbnail_path, m.duration_s, m.size_bytes,
                    m.width AS media_width, m.height AS media_height, m.fps, m.codec, m.aspect_ratio
             FROM posts p LEFT JOIN media m ON m.id = p.media_id WHERE p.id = ? LIMIT 1',
            [$postId]
        );
        if ($post === null) {
            return null;
        }
        $post['targets'] = static::targets($postId);
        $post['jobs'] = static::db()->select(
            'SELECT j.*, f.page_name, w.name AS worker_name
             FROM jobs j
             JOIN facebook_pages f ON f.id = j.page_id
             LEFT JOIN browser_workers w ON w.id = j.worker_id
             WHERE j.post_id = ? ORDER BY j.id ASC LIMIT 500',
            [$postId]
        );
        return $post;
    }

    /** @return list<array<string,mixed>> */
    public static function targets(int $postId): array
    {
        return static::db()->select(
            'SELECT pp.*, f.page_name, f.page_id AS fb_page_id, f.status AS page_status, a.label AS account_label
             FROM post_pages pp
             JOIN facebook_pages f ON f.id = pp.page_id
             JOIN facebook_accounts a ON a.id = f.account_id
             WHERE pp.post_id = ? ORDER BY f.page_name ASC',
            [$postId]
        );
    }

    /** @return list<array<string,mixed>> */
    public static function feed(int $userId, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $sql = 'SELECT p.*, m.kind AS media_kind, m.original_name, m.thumbnail_path,
                       (SELECT COUNT(*) FROM post_pages pp WHERE pp.post_id = p.id AND pp.status = \'PUBLISHED\') AS published_count,
                       (SELECT COUNT(*) FROM post_pages pp WHERE pp.post_id = p.id AND pp.status = \'FAILED\') AS failed_count
                FROM posts p LEFT JOIN media m ON m.id = p.media_id
                WHERE p.user_id = ?';
        $params = [$userId];

        if (!empty($filters['status']) && in_array($filters['status'], self::STATUSES, true)) {
            $sql .= ' AND p.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['kind'])) {
            $sql .= ' AND p.kind = ?';
            $params[] = (string) $filters['kind'];
        }
        if (!empty($filters['search'])) {
            $sql .= ' AND p.caption LIKE ?';
            $params[] = '%' . $filters['search'] . '%';
        }

        $sql .= ' ORDER BY p.created_at DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
        return static::db()->select($sql, $params);
    }

    /** @return array<string,mixed> */
    public static function summaryForUser(int $userId): array
    {
        $row = static::db()->first(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = 'DRAFT' THEN 1 ELSE 0 END) AS drafts,
                    SUM(CASE WHEN status = 'SCHEDULED' THEN 1 ELSE 0 END) AS scheduled,
                    SUM(CASE WHEN status = 'PUBLISHED' THEN 1 ELSE 0 END) AS published,
                    SUM(CASE WHEN status = 'FAILED' THEN 1 ELSE 0 END) AS failed,
                    SUM(CASE WHEN status IN ('USER_ACTION_REQUIRED','ACCOUNT_REAUTH_REQUIRED') THEN 1 ELSE 0 END) AS action_required,
                    SUM(CASE WHEN status = 'PARTIAL' THEN 1 ELSE 0 END) AS partial
             FROM posts WHERE user_id = ?",
            [$userId]
        ) ?? [];

        return array_map('intval', array_merge([
            'total' => 0, 'drafts' => 0, 'scheduled' => 0, 'published' => 0,
            'failed' => 0, 'action_required' => 0, 'partial' => 0,
        ], $row));
    }
}
