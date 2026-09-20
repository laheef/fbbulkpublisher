<?php
declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Time handling. Hard rule: everything is stored in UTC, everything is
 * displayed in the user's timezone.
 */
final class Clock
{
    public const STORAGE_TZ = 'UTC';

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone(self::STORAGE_TZ));
    }

    /** Storage-format timestamp (UTC) for DB writes. */
    public static function nowString(): string
    {
        return self::now()->format('Y-m-d H:i:s');
    }

    public static function toStorage(DateTimeInterface $dt): string
    {
        return (new DateTimeImmutable($dt->format('c')))->setTimezone(new DateTimeZone(self::STORAGE_TZ))->format('Y-m-d H:i:s');
    }

    /**
     * Convert a user-supplied local datetime + IANA timezone into a UTC
     * storage string. Ambiguous or invalid local times are rejected by design.
     *
     * @throws \InvalidArgumentException
     */
    public static function localToUtc(string $local, string $timezone): string
    {
        self::assertTimezone($timezone);
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', self::normaliseLocal($local), new DateTimeZone($timezone));
        if ($dt === false) {
            throw new \InvalidArgumentException('Invalid local datetime format (expected Y-m-d H:i[:s]).');
        }
        return $dt->setTimezone(new DateTimeZone(self::STORAGE_TZ))->format('Y-m-d H:i:s');
    }

    public static function utcToLocal(?string $utc, string $timezone, string $format = 'Y-m-d H:i:s'): ?string
    {
        if ($utc === null || $utc === '') {
            return null;
        }
        self::assertTimezone($timezone);
        $dt = new DateTimeImmutable($utc, new DateTimeZone(self::STORAGE_TZ));
        return $dt->setTimezone(new DateTimeZone($timezone))->format($format);
    }

    public static function parseUtc(?string $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        return new DateTimeImmutable($value, new DateTimeZone(self::STORAGE_TZ));
    }

    public static function iso(?string $utc): ?string
    {
        return self::parseUtc($utc)?->format(DATE_ATOM);
    }

    public static function addSeconds(int $seconds): string
    {
        return self::now()->modify(($seconds >= 0 ? '+' : '') . $seconds . ' seconds')->format('Y-m-d H:i:s');
    }

    public static function isPast(?string $utc): bool
    {
        $dt = self::parseUtc($utc);
        return $dt !== null && $dt <= self::now();
    }

    public static function secondsUntil(?string $utc): int
    {
        $dt = self::parseUtc($utc);
        if ($dt === null) {
            return PHP_INT_MAX;
        }
        return $dt->getTimestamp() - self::now()->getTimestamp();
    }

    public static function assertTimezone(string $timezone): void
    {
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new \InvalidArgumentException('Unknown timezone: ' . $timezone);
        }
    }

    /** @return list<string> */
    public static function commonTimezones(): array
    {
        return [
            'UTC', 'Asia/Karachi', 'Asia/Dubai', 'Asia/Kolkata', 'Asia/Dhaka', 'Asia/Jakarta',
            'Asia/Singapore', 'Asia/Riyadh', 'Europe/London', 'Europe/Paris', 'Europe/Berlin',
            'Europe/Istanbul', 'Africa/Lagos', 'Africa/Nairobi', 'America/New_York',
            'America/Chicago', 'America/Denver', 'America/Los_Angeles', 'America/Sao_Paulo',
            'Australia/Sydney',
        ];
    }

    private static function normaliseLocal(string $value): string
    {
        $value = trim(str_replace('T', ' ', $value));
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value) === 1) {
            return $value . ':00';
        }
        return $value;
    }
}
