<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use App\Core\Database;

final class User extends Model
{
    protected static string $table = 'users';
    protected static array $fillable = [
        'name', 'email', 'password_hash', 'role', 'timezone', 'status',
        'last_login_at', 'last_login_ip',
    ];

    /** @return array<string,mixed>|null */
    public static function findByEmail(string $email): ?array
    {
        return static::db()->first('SELECT * FROM users WHERE email = ? LIMIT 1', [mb_strtolower(trim($email))]);
    }

    /**
     * @param array<string,mixed> $data
     * @return array{ok:bool,user?:array<string,mixed>,errors?:array<string,string>}
     */
    public static function register(string $name, string $email, string $password, string $timezone = 'UTC', string $role = 'user'): array
    {
        $email = mb_strtolower(trim($email));

        if (static::findByEmail($email) !== null) {
            return ['ok' => false, 'errors' => ['email' => 'That email address is already registered.']];
        }

        $id = static::db()->insert('users', [
            'name'          => trim($name),
            'email'         => $email,
            'password_hash' => self::hash($password),
            'role'          => $role,
            'timezone'      => $timezone,
            'status'        => 'ACTIVE',
        ]);

        $user = static::find($id);
        return $user === null
            ? ['ok' => false, 'errors' => ['email' => 'Unable to create the account.']]
            : ['ok' => true, 'user' => $user];
    }

    public static function hash(string $password): string
    {
        $algo = Config::get('security.password_algo', PASSWORD_ARGON2ID);
        $options = (array) Config::get('security.password_options', []);

        // Fall back gracefully where Argon2id is unavailable (older PHP builds).
        if (!defined('PASSWORD_ARGON2ID')) {
            $algo = PASSWORD_BCRYPT;
            $options = ['cost' => 12];
        }

        return password_hash($password, $algo, $options);
    }

    public static function changePassword(int $userId, string $newPassword): bool
    {
        return static::modify($userId, ['password_hash' => self::hash($newPassword)]) > 0;
    }

    public static function touchLogin(int $userId, string $ip): void
    {
        static::db()->update('users', [
            'last_login_at' => \App\Core\Clock::nowString(),
            'last_login_ip' => $ip,
        ], ['id' => $userId]);
    }

    public static function setTimezone(int $userId, string $timezone): void
    {
        static::modify($userId, ['timezone' => $timezone]);
    }

    /** @return array<string,mixed>|null Safe projection for display (never the hash). */
    public static function safe(int $id): ?array
    {
        $user = static::find($id);
        if ($user === null) {
            return null;
        }
        unset($user['password_hash']);
        return $user;
    }

    public static function purgeOldLoginAttempts(int $days = 7): int
    {
        return Database::instance()->query(
            'DELETE FROM login_attempts WHERE created_at < ?',
            [\App\Core\Clock::now()->modify("-{$days} days")->format('Y-m-d H:i:s')]
        )->rowCount();
    }
}
