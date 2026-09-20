<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Rotating file logger. Every message is passed through redaction first, so
 * cookies/tokens/passwords can never reach disk (see docs/SECURITY.md).
 */
final class Logger
{
    private static ?Logger $instance = null;
    private string $path;
    private string $minLevel;
    private const LEVELS = ['debug' => 10, 'info' => 20, 'warn' => 30, 'error' => 40];

    private function __construct(string $path, string $minLevel = 'info')
    {
        $this->path = rtrim($path, '/');
        $this->minLevel = $minLevel;
        if (!is_dir($this->path)) {
            mkdir($this->path, 0775, true);
        }
    }

    public static function boot(array $cfg): void
    {
        self::$instance = new self((string) ($cfg['path'] ?? sys_get_temp_dir()), (string) ($cfg['level'] ?? 'info'));
    }

    public static function instance(): Logger
    {
        return self::$instance ??= new self(sys_get_temp_dir() . '/linkeasy-logs');
    }

    /** @param array<string,mixed> $context */
    public static function log(string $channel, string $level, string $message, array $context = []): void
    {
        self::instance()->write($channel, $level, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function debug(string $message, array $context = []): void
    {
        self::log('app', 'debug', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function info(string $message, array $context = []): void
    {
        self::log('app', 'info', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function warn(string $message, array $context = []): void
    {
        self::log('app', 'warn', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function error(string $message, array $context = []): void
    {
        self::log('app', 'error', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function write(string $channel, string $level, string $message, array $context = []): void
    {
        $channel = preg_replace('/[^a-z0-9_-]/i', '', $channel) ?: 'app';
        $level = strtolower($level);

        if ((self::LEVELS[$level] ?? 20) < (self::LEVELS[$this->minLevel] ?? 20)) {
            return;
        }

        $line = Support::jsonEncode([
            'ts'      => Clock::now()->format('Y-m-d\TH:i:s.v\Z'),
            'level'   => $level,
            'channel' => $channel,
            'message' => Support::redact($message),
            'context' => array_map(
                static fn ($v): mixed => is_string($v) ? Support::redact($v) : $v,
                $context
            ),
        ]);

        $file = $this->path . '/' . ($channel === 'errors' || $level === 'error' ? 'errors.log' : $channel . '.log');
        $this->rotateIfNeeded($file);
        file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function rotateIfNeeded(string $file, int $maxBytes = 10_485_760, int $keep = 5): void
    {
        if (!is_file($file) || filesize($file) < $maxBytes) {
            return;
        }
        for ($i = $keep - 1; $i >= 1; $i--) {
            $from = $file . '.' . $i;
            if (is_file($from)) {
                @rename($from, $file . '.' . ($i + 1));
            }
        }
        @rename($file, $file . '.1');
    }
}
