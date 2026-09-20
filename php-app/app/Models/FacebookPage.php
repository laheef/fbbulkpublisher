<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Clock;

/**
 * A Facebook Page the operator is authorised to manage, discovered by the
 * Windows worker through the authenticated browser session.
 */
final class FacebookPage extends Model
{
    protected static string $table = 'facebook_pages';
    protected static array $fillable = [
        'user_id', 'account_id', 'page_id', 'page_name', 'page_url', 'avatar_url', 'category',
        'status', 'last_verified_at', 'last_published_at', 'last_published_url',
        'scheduled_count', 'failed_count',
    ];

    public const STATUSES = ['ENABLED', 'DISABLED', 'ERROR', 'AUTH_REQUIRED', 'CHALLENGE_REQUIRED'];

    /** @return list<array<string,mixed>> */
    public static function forUser(int $userId): array
    {
        return static::db()->select(
            'SELECT p.*, a.label AS account_label, a.status AS account_status, w.name AS worker_name
             FROM facebook_pages p
             JOIN facebook_accounts a ON a.id = p.account_id
             LEFT JOIN browser_workers w ON w.id = a.worker_id
             WHERE p.user_id = ? ORDER BY p.status ASC, p.page_name ASC',
            [$userId]
        );
    }

    /** @return list<array<string,mixed>> */
    public static function forAccount(int $accountId): array
    {
        return static::db()->select(
            'SELECT * FROM facebook_pages WHERE account_id = ? ORDER BY page_name ASC',
            [$accountId]
        );
    }

    /**
     * Upsert discovered Pages. Existing rows keep their scheduling history and
     * any operator-set ENABLED/DISABLED choice.
     *
     * @param list<array<string,mixed>> $pages
     * @return array{added:int,updated:int,ids:list<int>}
     */
    public static function syncFromWorker(int $userId, int $accountId, array $pages): array
    {
        $added = 0;
        $updated = 0;
        $ids = [];

        foreach ($pages as $page) {
            $pageId = trim((string) ($page['page_id'] ?? $page['id'] ?? ''));
            $name = trim((string) ($page['name'] ?? $page['page_name'] ?? ''));
            if ($pageId === '' || $name === '') {
                continue;
            }

            $existing = static::db()->first(
                'SELECT * FROM facebook_pages WHERE account_id = ? AND page_id = ? LIMIT 1',
                [$accountId, $pageId]
            );

            $payload = [
                'user_id'           => $userId,
                'account_id'        => $accountId,
                'page_id'           => $pageId,
                'page_name'         => mb_substr($name, 0, 190),
                'page_url'          => $page['url'] ?? $page['page_url'] ?? ('https://www.facebook.com/' . $pageId),
                'avatar_url'        => isset($page['avatar_url']) ? mb_substr((string) $page['avatar_url'], 0, 500) : null,
                'category'          => isset($page['category']) ? mb_substr((string) $page['category'], 0, 120) : null,
                'last_verified_at'  => Clock::nowString(),
            ];

            if ($existing === null) {
                $payload['status'] = 'ENABLED';
                $id = static::db()->insert('facebook_pages', $payload);
                $added++;
            } else {
                $id = (int) $existing['id'];
                unset($payload['user_id'], $payload['account_id'], $payload['page_id']);
                // Recover a page automatically if the session verified again.
                if (in_array((string) $existing['status'], ['AUTH_REQUIRED', 'CHALLENGE_REQUIRED', 'ERROR'], true)) {
                    $payload['status'] = 'ENABLED';
                }
                static::db()->update('facebook_pages', $payload, ['id' => $id]);
                $updated++;
            }
            $ids[] = $id;
        }

        FacebookAccount::refreshPageCount($accountId);

        return ['added' => $added, 'updated' => $updated, 'ids' => $ids];
    }

    public static function setEnabled(int $pageId, bool $enabled): void
    {
        static::db()->update('facebook_pages', ['status' => $enabled ? 'ENABLED' : 'DISABLED'], ['id' => $pageId]);
    }

    public static function markPublished(int $pageId, string $url, string $publishedAt): void
    {
        static::db()->update('facebook_pages', [
            'last_published_at'  => $publishedAt,
            'last_published_url' => mb_substr($url, 0, 500),
            'last_verified_at'   => $publishedAt,
        ], ['id' => $pageId]);
    }

    public static function refreshCounters(int $pageId): void
    {
        $scheduled = (int) static::db()->scalar(
            "SELECT COUNT(*) FROM jobs WHERE page_id = ? AND status IN ('QUEUED','CLAIMED','PROCESSING','UPLOADING','PUBLISHING','VERIFYING','RETRYING','SCHEDULED')",
            [$pageId]
        );
        $failed = (int) static::db()->scalar(
            "SELECT COUNT(*) FROM jobs WHERE page_id = ? AND status = 'FAILED'",
            [$pageId]
        );
        static::db()->update('facebook_pages', ['scheduled_count' => $scheduled, 'failed_count' => $failed], ['id' => $pageId]);
    }

    /** @return array<string,mixed> */
    public static function summaryForUser(int $userId): array
    {
        $row = static::db()->first(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = 'ENABLED' THEN 1 ELSE 0 END) AS enabled,
                    SUM(CASE WHEN status = 'DISABLED' THEN 1 ELSE 0 END) AS disabled,
                    SUM(CASE WHEN status IN ('ERROR','AUTH_REQUIRED','CHALLENGE_REQUIRED') THEN 1 ELSE 0 END) AS errored,
                    SUM(scheduled_count) AS scheduled,
                    SUM(failed_count) AS failed
             FROM facebook_pages WHERE user_id = ?",
            [$userId]
        ) ?? [];

        return [
            'total'     => (int) ($row['total'] ?? 0),
            'enabled'   => (int) ($row['enabled'] ?? 0),
            'disabled'  => (int) ($row['disabled'] ?? 0),
            'errored'   => (int) ($row['errored'] ?? 0),
            'scheduled' => (int) ($row['scheduled'] ?? 0),
            'failed'    => (int) ($row['failed'] ?? 0),
        ];
    }

    /**
     * @param list<int> $pageIds
     * @return list<array<string,mixed>> Rows owned by $userId, with account+worker joined.
     */
    public static function ownedByIds(int $userId, array $pageIds): array
    {
        $pageIds = array_values(array_filter(array_map('intval', $pageIds), static fn (int $i): bool => $i > 0));
        if ($pageIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($pageIds), '?'));
        return static::db()->select(
            "SELECT p.*, a.label AS account_label, a.status AS account_status, a.profile_ref, a.worker_id
             FROM facebook_pages p
             JOIN facebook_accounts a ON a.id = p.account_id
             WHERE p.user_id = ? AND p.id IN ({$placeholders})",
            array_merge([$userId], $pageIds)
        );
    }
}
