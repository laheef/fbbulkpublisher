<?php
declare(strict_types=1);

namespace App\Core;

/**
 * HTTP request abstraction. All superglobals are read through here so the
 * rest of the app never touches $_GET/$_POST/$_SERVER directly.
 */
final class Request
{
    /** @param array<string,mixed> $query @param array<string,mixed> $post @param array<string,mixed> $server @param array<string,mixed> $files */
    public function __construct(
        private array $query = [],
        private array $post = [],
        private array $server = [],
        private array $files = [],
        private ?string $rawBody = null,
    ) {
    }

    public static function capture(): self
    {
        return new self($_GET, $_POST, $_SERVER, $_FILES, file_get_contents('php://input') ?: null);
    }

    public function method(): string
    {
        return strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));
    }

    public function path(): string
    {
        $uri = (string) ($this->server['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        // Support deployments in a subdirectory + reverse proxies.
        $scriptDir = rtrim(str_replace('\\', '/', dirname((string) ($this->server['SCRIPT_NAME'] ?? ''))), '/');
        if ($scriptDir !== '' && $scriptDir !== '/' && str_starts_with($path, $scriptDir)) {
            $path = substr($path, strlen($scriptDir));
        }
        $path = '/' . ltrim($path, '/');
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    public function isAjax(): bool
    {
        $requestedWith = $this->server['HTTP_X_REQUESTED_WITH'] ?? '';
        return strtolower((string) $requestedWith) === 'xmlhttprequest'
            || str_contains($this->header('Accept') ?? '', 'application/json')
            || str_starts_with($this->path(), '/api/');
    }

    public function expectsJson(): bool
    {
        return $this->isAjax();
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function post(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);
        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key, $default);
        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->input($key, $default);
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true);
    }

    /** @return list<string> */
    public function arrayOfStrings(string $key): array
    {
        $value = $this->input($key, []);
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_scalar($item)) {
                $out[] = trim((string) $item);
            }
        }
        return array_values(array_unique(array_filter($out, static fn (string $v): bool => $v !== '')));
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        if ($this->rawBody === null || $this->rawBody === '') {
            return [];
        }
        $decoded = json_decode($this->rawBody, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function jsonField(string $key, mixed $default = null): mixed
    {
        return $this->json()[$key] ?? $default;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $this->server[$key] ?? $this->server[strtoupper(str_replace('-', '_', $name))] ?? null;
        return $value === null ? null : (string) $value;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('Authorization');
        if ($auth !== null && preg_match('/^Bearer\s+(.+)$/i', $auth, $m) === 1) {
            return trim($m[1]);
        }
        return $this->header('X-LinkEasy-Token');
    }

    public function ip(): string
    {
        // Do not trust X-Forwarded-For unless the deployment sets it explicitly.
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function userAgent(): string
    {
        return mb_substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function isSecure(): bool
    {
        return (!empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off')
            || (($this->server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return array_merge($this->query, $this->post);
    }

    /** @return array<string,mixed> */
    public function server(): array
    {
        return $this->server;
    }
}
