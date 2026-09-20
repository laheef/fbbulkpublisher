<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Session + CSRF management with hardened cookie flags and rotation on login.
 */
final class Session
{
    public static function start(array $cfg): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name((string) ($cfg['name'] ?? 'linkeasy_session'));
        session_set_cookie_params([
            'lifetime' => (int) ($cfg['lifetime'] ?? 0),
            'path'     => '/',
            'domain'   => '',
            'secure'   => (bool) ($cfg['secure'] ?? false),
            'httponly' => true,
            'samesite' => (string) ($cfg['samesite'] ?? 'Lax'),
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_start();

        // Idle expiry.
        $idle = (int) ($cfg['idle_timeout'] ?? 7200);
        $last = (int) ($_SESSION['_last_activity'] ?? time());
        if (time() - $last > $idle) {
            self::destroy();
            session_start();
        }
        $_SESSION['_last_activity'] = time();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
            }
            session_destroy();
        }
    }

    /** Flash message surviving exactly one redirect. @param array<string,mixed> $payload */
    public static function flash(string $type, string $message, array $payload = []): void
    {
        $_SESSION['_flash'] = ['type' => $type, 'message' => $message] + $payload;
    }

    /** @return array<string,mixed>|null */
    public static function pullFlash(): ?array
    {
        $flash = $_SESSION['_flash'] ?? null;
        unset($_SESSION['_flash']);
        return is_array($flash) ? $flash : null;
    }

    public static function csrfToken(int $ttl = 14400): string
    {
        $now = time();
        $token = $_SESSION['_csrf'] ?? null;
        $issued = (int) ($_SESSION['_csrf_at'] ?? 0);

        if (!is_string($token) || $token === '' || ($now - $issued) > $ttl) {
            $token = bin2hex(random_bytes(32));
            $_SESSION['_csrf'] = $token;
            $_SESSION['_csrf_at'] = $now;
        }
        return $token;
    }

    public static function verifyCsrf(?string $token): bool
    {
        $expected = $_SESSION['_csrf'] ?? null;
        if (!is_string($expected) || !is_string($token)) {
            return false;
        }
        return hash_equals($expected, $token);
    }

    public static function rotateCsrf(): void
    {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        $_SESSION['_csrf_at'] = time();
    }
}
