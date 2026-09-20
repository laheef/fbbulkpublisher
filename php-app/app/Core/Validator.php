<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Declarative input validation. Errors are returned as a flat map so they can
 * be re-displayed next to the offending field.
 */
final class Validator
{
    /** @var array<string,string> */
    private array $errors = [];
    /** @var array<string,mixed> */
    private array $clean = [];

    /** @param array<string,mixed> $data */
    private function __construct(private array $data, private array $labels = [])
    {
    }

    /** @param array<string,mixed> $data @param array<string,string> $labels */
    public static function make(array $data, array $labels = []): self
    {
        return new self($data, $labels);
    }

    public function required(string $field): self
    {
        $value = $this->data[$field] ?? null;
        if (is_array($value) ? $value === [] : trim((string) $value) === '') {
            $this->fail($field, 'is required.');
        } else {
            $this->clean[$field] = is_string($value) ? trim($value) : $value;
        }
        return $this;
    }

    public function email(string $field): self
    {
        $value = trim((string) ($this->data[$field] ?? ''));
        if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->fail($field, 'must be a valid email address.');
        } elseif ($value !== '') {
            $this->clean[$field] = mb_strtolower($value);
        }
        return $this;
    }

    public function min(string $field, int $length): self
    {
        $value = (string) ($this->data[$field] ?? '');
        if ($value !== '' && mb_strlen($value) < $length) {
            $this->fail($field, "must be at least {$length} characters.");
        }
        return $this;
    }

    public function max(string $field, int $length): self
    {
        $value = (string) ($this->data[$field] ?? '');
        if (mb_strlen($value) > $length) {
            $this->fail($field, "must not exceed {$length} characters.");
        } else {
            $this->clean[$field] = $value;
        }
        return $this;
    }

    public function in(string $field, array $allowed): self
    {
        $value = (string) ($this->data[$field] ?? '');
        if (!in_array($value, $allowed, true)) {
            $this->fail($field, 'has an unsupported value.');
        } else {
            $this->clean[$field] = $value;
        }
        return $this;
    }

    public function timezone(string $field): self
    {
        $value = (string) ($this->data[$field] ?? '');
        if (!in_array($value, \DateTimeZone::listIdentifiers(), true)) {
            $this->fail($field, 'is not a recognised timezone.');
        } else {
            $this->clean[$field] = $value;
        }
        return $this;
    }

    public function datetime(string $field): self
    {
        $value = trim((string) ($this->data[$field] ?? ''));
        if ($value !== '' && preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $value) !== 1) {
            $this->fail($field, 'must be a date and time (YYYY-MM-DD HH:MM).');
        } else {
            $this->clean[$field] = $value;
        }
        return $this;
    }

    public function integer(string $field, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): self
    {
        $raw = $this->data[$field] ?? null;
        if ($raw === null || $raw === '') {
            return $this;
        }
        if (!is_numeric($raw) || (int) $raw != $raw) {
            $this->fail($field, 'must be a whole number.');
            return $this;
        }
        $value = (int) $raw;
        if ($value < $min || $value > $max) {
            $this->fail($field, "must be between {$min} and {$max}.");
            return $this;
        }
        $this->clean[$field] = $value;
        return $this;
    }

    public function boolean(string $field): self
    {
        $value = $this->data[$field] ?? false;
        if (is_bool($value)) {
            $this->clean[$field] = $value;
            return $this;
        }
        $this->clean[$field] = in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true);
        return $this;
    }

    public function url(string $field): self
    {
        $value = trim((string) ($this->data[$field] ?? ''));
        if ($value === '') {
            return $this;
        }
        $valid = filter_var($value, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true);
        if (!$valid) {
            $this->fail($field, 'must be a valid http(s) URL.');
            return $this;
        }
        $this->clean[$field] = $value;
        return $this;
    }

    /** Reject control characters that could corrupt stored text or logs. */
    public function text(string $field, int $max = 5000): self
    {
        $value = (string) ($this->data[$field] ?? '');
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        if (mb_strlen($value) > $max) {
            $this->fail($field, "must not exceed {$max} characters.");
            return $this;
        }
        $this->clean[$field] = trim($value);
        return $this;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<string,mixed> */
    public function validated(): array
    {
        return $this->clean;
    }

    private function fail(string $field, string $rule): void
    {
        $label = $this->labels[$field] ?? str_replace('_', ' ', $field);
        $this->errors[$field] = ucfirst($label) . ' ' . $rule;
    }
}
