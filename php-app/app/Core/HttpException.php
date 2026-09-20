<?php
declare(strict_types=1);

namespace App\Core;

/**
 * HTTP exception carrying a status code. Controllers throw these; the kernel
 * renders them (HTML page for browsers, JSON envelope for API clients).
 */
final class HttpException extends \RuntimeException
{
    public function __construct(private int $statusCode, string $message = '', private array $details = [])
    {
        parent::__construct($message, $statusCode);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /** @return array<string,mixed> */
    public function details(): array
    {
        return $this->details;
    }

    public static function badRequest(string $message = 'Bad request.', array $details = []): self
    {
        return new self(400, $message, $details);
    }

    public static function unauthorized(string $message = 'Authentication required.'): self
    {
        return new self(401, $message);
    }

    public static function forbidden(string $message = 'You are not allowed to perform this action.'): self
    {
        return new self(403, $message);
    }

    public static function notFound(string $message = 'Not found.'): self
    {
        return new self(404, $message);
    }

    public static function conflict(string $message = 'Conflict.', array $details = []): self
    {
        return new self(409, $message, $details);
    }

    public static function validation(string $message = 'The submitted data is invalid.', array $details = []): self
    {
        return new self(422, $message, $details);
    }

    public static function tooMany(string $message = 'Too many requests.'): self
    {
        return new self(429, $message);
    }
}
