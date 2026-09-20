<?php
declare(strict_types=1);

namespace App\Core;

use App\Models\ActivityLog;
use App\Models\User;

/**
 * Authentication + authorisation gate.
 *
 * - Sessions are regenerated on privilege change.
 * - Brute-force attempts are throttled per (email, ip).
 * - Every authorisation decision funnels through can()/requireOwner() so
 *   multi-tenant isolation is enforced in exactly one place.
 */
final class Auth
{
    private static ?array $user = null;

    public static function attempt(string $email, string $password, Request $request): array
    {
        $email = mb_strtolower(trim($email));
        $maxAttempts = Config::int('security.max_login_attempts', 5);
        $lockout = Config::int('security.lockout_minutes', 15);

        $recent = (int) Database::instance()->scalar(
            'SELECT COUNT(*) FROM login_attempts WHERE email = ? AND ip_address = ? AND successful = 0 AND created_at >= ?',
            [$email, $request->ip(), Clock::now()->modify("-{$lockout} minutes")->format('Y-m-d H:i:s')]
        );

        if ($recent >= $maxAttempts) {
            Logger::warn('Login throttled', ['email' => $email, 'ip' => $request->ip()]);
            return ['ok' => false, 'reason' => 'throttled'];
        }

        $user = User::findByEmail($email);

        // Always run a hash comparison to keep timing uniform for unknown users.
        $hash = $user['password_hash'] ?? '$argon2id$v=19$m=65536,t=4,p=2$AAAAAAAAAAAAAAAAAAAAAA$AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
        $valid = password_verify($password, $hash);

        if ($user === null || !$valid) {
            Database::instance()->insert('login_attempts', [
                'email'      => $email,
                'ip_address' => $request->ip(),
                'successful' => 0,
            ]);
            return ['ok' => false, 'reason' => 'invalid'];
        }

        if (($user['status'] ?? 'ACTIVE') !== 'ACTIVE') {
            return ['ok' => false, 'reason' => 'suspended'];
        }

        Database::instance()->insert('login_attempts', [
            'email'      => $email,
            'ip_address' => $request->ip(),
            'successful' => 1,
        ]);

        self::login($user, $request);
        return ['ok' => true, 'reason' => 'ok'];
    }

    /** @param array<string,mixed> $user */
    public static function login(array $user, Request $request): void
    {
        Session::regenerate();
        Session::rotateCsrf();
        Session::put('user_id', (int) $user['id']);
        Session::put('user_role', (string) $user['role']);
        Session::put('user_tz', (string) ($user['timezone'] ?? 'UTC'));
        self::$user = $user;

        User::touchLogin((int) $user['id'], $request->ip());
        ActivityLog::record((int) $user['id'], 'auth.login', 'user', (int) $user['id'], $request->ip());
    }

    public static function logout(): void
    {
        Session::destroy();
        self::$user = null;
    }

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }
        $id = Session::get('user_id');
        if (!is_int($id) && !is_numeric($id)) {
            return null;
        }
        self::$user = User::find((int) $id);
        return self::$user;
    }

    public static function id(): ?int
    {
        $user = self::user();
        return $user === null ? null : (int) $user['id'];
    }

    /** Timezone used for every timestamp rendered to this user. */
    public static function timezone(): string
    {
        return (string) (self::user()['timezone'] ?? 'UTC');
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function isAdmin(): bool
    {
        return (self::user()['role'] ?? 'user') === 'admin';
    }

    /**
     * Multi-tenant check: a row (array with a user_id column) may only be
     * touched by its owner, unless the actor is an admin acting explicitly.
     *
     * @param array<string,mixed>|null $row
     */
    public static function owns(?array $row): bool
    {
        if ($row === null) {
            return false;
        }
        $ownerId = isset($row['user_id']) ? (int) $row['user_id'] : null;
        if ($ownerId === null) {
            return false;
        }
        return $ownerId === self::id();
    }

    /** Throwing variant used by controllers. */
    public static function requireOwns(?array $row, string $entity = 'resource'): array
    {
        if ($row === null) {
            throw new HttpException(404, "The requested {$entity} does not exist.");
        }
        if (!self::owns($row)) {
            // Deliberately 404 rather than 403: never confirm the existence of
            // another tenant's data.
            Logger::warn('Tenant isolation violation blocked', [
                'actor_user_id' => self::id(),
                'entity'        => $entity,
                'entity_id'     => $row['id'] ?? null,
            ]);
            throw new HttpException(404, "The requested {$entity} does not exist.");
        }
        return $row;
    }
}
