<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Immutable-ish configuration repository.
 *
 * Values come from config/config.php which itself reads environment variables
 * (or falls back to sane defaults). Dot notation is supported for lookups.
 */
final class Config
{
    /** @var array<string,mixed> */
    private static array $items = [];
    private static bool $loaded = false;

    public static function load(string $file): void
    {
        if (!is_file($file)) {
            throw new \RuntimeException("Configuration file not found: {$file}");
        }
        /** @var array<string,mixed> $data */
        $data = require $file;
        self::$items = $data;
        self::$loaded = true;
    }

    public static function loaded(): bool
    {
        return self::$loaded;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $cursor = self::$items;
        foreach ($segments as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return $default;
            }
            $cursor = $cursor[$segment];
        }
        return $cursor;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default);
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key, $default);
        return is_numeric($value) ? (int) $value : $default;
    }

    public static function str(string $key, string $default = ''): string
    {
        $value = self::get($key, $default);
        return is_scalar($value) ? (string) $value : $default;
    }

    /** @return array<string,mixed> */
    public static function all(): array
    {
        return self::$items;
    }
}
