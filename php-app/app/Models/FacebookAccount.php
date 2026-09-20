<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Clock;
use App\Core\Support;

/**
 * A Facebook identity the operator has authenticated manually in a persistent
 * browser profile. No password, cookie or token ever reaches this server.
 */
final class FacebookAccount extends Model
{
    protected static string $table = 'facebook_accounts';
    protected static array $fillable = [
        'user_id', 'label', 'fb_account_name', 'fb_account_id', 'profile_ref', 'worker_id',
        'status', 'last_verified_at', 'last_error_code', 'last_error', 'page_count',
    ];

    public const STATUSES = ['CONNECTED', 'DISCONNECTED', 'AUTH_REQUIRED', 'CHALLENGE_REQUIRED', 'DISABLED', 'ERROR'];

    public static function newProfileRef(): string
    {
        return 'acct_' . substr(hash('sha256', Support::uuid4() . microtime(true)), 0, 20);
    }

    /** @return list<array<string,mixed>> */
    public static function forUser(int $userId): array
    {
        return static::db()->select(
            'SELECT * FROM facebook_accounts WHERE user_id = ? ORDER BY created_at DESC',
            [$userId]
        );
    }

    /** @return list<array<string,mixed>> */
    public static function connectedForUser(int $userId): array
    {
        return static::db()->select(
            "SELECT * FROM facebook_accounts WHERE user_id = ? AND status = 'CONNECTED' AND worker_id IS NOT NULL
             ORDER BY created_at ASC",
            [$userId]
        );
    }

    public static function setStatus(int $accountId, string $status, ?string $errorCode = null, ?string $errorMessage = null): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            return;
        }
        static::db()->update('facebook_accounts', [
            'status'          => $status,
            'last_error_code' => $errorCode,
            'last_error'      => $errorMessage === null ? null : mb_substr(Support::redact($errorMessage), 0, 500),
            'last_verified_at' => $status === 'CONNECTED' ? Clock::nowString() : null,
        ], ['id' => $accountId]);
    }

    /** @return array<string,mixed>|null Account with its Pages nested. */
    public static function withPages(int $accountId): ?array
    {
        $account = static::find($accountId);
        if ($account === null) {
            return null;
        }
        $account['pages'] = FacebookPage::forAccount($accountId);
        return $account;
    }

    public static function refreshPageCount(int $accountId): void
    {
        $count = (int) static::db()->scalar('SELECT COUNT(*) FROM facebook_pages WHERE account_id = ?', [$accountId]);
        static::db()->update('facebook_accounts', ['page_count' => $count], ['id' => $accountId]);
    }

    /** @return array<string,mixed> */
    public static function summaryForUser(int $userId): array
    {
        $row = static::db()->first(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = \'CONNECTED\' THEN 1 ELSE 0 END) AS connected,
                    SUM(CASE WHEN status IN (\'AUTH_REQUIRED\',\'CHALLENGE_REQUIRED\') THEN 1 ELSE 0 END) AS needs_action,
                    SUM(CASE WHEN status = \'ERROR\' THEN 1 ELSE 0 END) AS errored
             FROM facebook_accounts WHERE user_id = ?',
            [$userId]
        ) ?? [];

        return [
            'total'     => (int) ($row['total'] ?? 0),
            'connected' => (int) ($row['connected'] ?? 0),
            'action'    => (int) ($row['needs_action'] ?? 0),
            'errored'   => (int) ($row['errored'] ?? 0),
        ];
    }
}
