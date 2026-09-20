<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Small shared helpers: UUIDv4, idempotency keys, safe filenames, redaction.
 */
final class Support
{
    public static function uuid4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Deterministic idempotency key for a publish job.
     *
     * The same (post, page, run-index) triple can never produce two jobs, so a
     * crashed worker that retries after reconnecting cannot double-publish.
     */
    public static function idempotencyKey(int $postId, int $pageId, int $runIndex = 0, string $salt = ''): string
    {
        return hash('sha256', implode('|', ['linkeasy', $postId, $pageId, $runIndex, $salt]));
    }

    /** Cryptographically strong opaque token (returned to caller in plaintext once). */
    public static function token(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    /** Constant-time comparison that tolerates empty strings. */
    public static function safeEquals(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }
        return hash_equals($a, $b);
    }

    /** Filesystem-safe filename, never trusting the client-supplied name. */
    public static function safeFileName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?? 'file';
        $name = trim($name, '-.');
        if ($name === '' || $name === '.') {
            $name = 'file';
        }
        return mb_substr($name, 0, 120);
    }

    /** Prevent path traversal for any relative path we build from DB values. */
    public static function assertSafeRelativePath(string $path): void
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new \InvalidArgumentException('Invalid path.');
        }
        $normalised = str_replace('\\', '/', $path);
        if (str_starts_with($normalised, '/') || preg_match('#(^|/)\.\.(/|$)#', $normalised) === 1) {
            throw new \InvalidArgumentException('Path traversal detected.');
        }
    }

    public static function bytesToHuman(int|float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $bytes = (float) $bytes;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, $i === 0 ? 0 : 1) . ' ' . $units[$i];
    }

    /** Hard truncate with an ellipsis, preserving words where possible. */
    public static function truncate(?string $text, int $length = 60): string
    {
        $text = trim((string) $text);
        if ($text === '') {
            return '';
        }
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        $cut = mb_substr($text, 0, $length);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > $length * 0.6) {
            $cut = mb_substr($cut, 0, $space);
        }
        return $cut . '…';
    }

    public static function excerpt(?string $text, int $length = 90): string
    {
        $text = trim(preg_replace('/\s+/', ' ', (string) $text) ?? '');
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        return mb_substr($text, 0, $length - 1) . '…';
    }

    /**
     * Strip anything that looks like a credential before it is written to a
     * log line, a notification body, or an diagnostics export.
     */
    public static function redact(string $message): string
    {
        $patterns = [
            '/\b(?:c_user|xs|fr|datr|sb|presence)=\S+/i' => 'cookie=[redacted]',
            '/\bBearer\s+[A-Za-z0-9\-._~+\/]+=*/i'       => 'Bearer [redacted]',
            // Prefixed keys matter: access_token, refresh_token, client_secret and
            // api_key are the shapes Facebook credentials actually take, so the
            // pattern allows any identifier around the sensitive word.
            '/([\w\-]*(?:password|passwd|pwd|secret|token|api[_-]?key)[\w\-]*)\s*[:=]\s*(?:"[^"]*"|\'[^\']*\'|[^\s,;&]+)/i' => '$1=[redacted]',
            '/lkw_[A-Za-z0-9\-_]{16,}/'                 => 'lkw_[redacted]',
        ];
        return preg_replace(array_keys($patterns), array_values($patterns), $message) ?? $message;
    }

    /** @param array<string,mixed> $context */
    public static function jsonEncode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /** @return array<string,mixed> */
    public static function jsonDecode(?string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
