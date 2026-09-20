<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Presentation helpers used by the Blade-less templates: icons, status pills,
 * relative times, empty states and small progress bars.
 *
 * Everything returns escaped HTML — templates can echo the result directly.
 */
final class Ui
{
    /** Inline SVG icon set. No external icon font or CDN is required. */
    public static function icon(string $name, int $size = 18): string
    {
        $paths = [
            'dashboard' => '<path d="M4 13h6V4H4v9Zm0 7h6v-4H4v4Zm10 0h6V11h-6v9Zm0-16v4h6V4h-6Z"/>',
            'accounts'  => '<path d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4Zm0 2c-3.3 0-8 1.7-8 5v1h16v-1c0-3.3-4.7-5-8-5Z"/>',
            'pages'     => '<path d="M6 3h9l4 4v14H6Zm2 2v14h9V8h-3V5Zm1.5 6h6v1.5h-6Zm0 3h6V15h-6Z"/>',
            'media'     => '<path d="M4 5h16v14H4Zm2 2v8l3.5-4 2.5 3 3-4 3 5V7Z"/>',
            'posts'     => '<path d="M4 4h16v3H4Zm0 5h16v2H4Zm0 4h12v2H4Zm0 4h9v2H4Z"/>',
            'calendar'  => '<path d="M7 2v2H5a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2V2h-2v2H9V2ZM5 9h14v10H5Z"/>',
            'queue'     => '<path d="M3 5h14v2H3Zm0 4h18v2H3Zm0 4h14v2H3Zm0 4h18v2H3Z"/>',
            'workers'   => '<path d="M4 4h16v6H4Zm0 10h7v6H4Zm9 0h7v6h-7Z"/>',
            'analytics' => '<path d="M4 20V10h3v10Zm6.5 0V4h3v16ZM17 20v-7h3v7Z"/>',
            'bell'      => '<path d="M12 22a2.5 2.5 0 0 0 2.5-2.5h-5A2.5 2.5 0 0 0 12 22Zm7-5-1.5-1.6V10a5.5 5.5 0 0 0-4.3-5.4V4a1.2 1.2 0 0 0-2.4 0v.6A5.5 5.5 0 0 0 6.5 10v5.4L5 17v1h14Z"/>',
            'settings'  => '<path d="M12 8a4 4 0 1 0 4 4 4 4 0 0 0-4-4Zm8.4 4a8 8 0 0 0-.1-1.2l2-1.5-2-3.4-2.3 1a8 8 0 0 0-2-1.2L15.6 3h-3.9l-.4 2.5a8 8 0 0 0-2 1.2l-2.3-1-2 3.4 2 1.5a8 8 0 0 0 0 2.4l-2 1.5 2 3.4 2.3-1a8 8 0 0 0 2 1.2l.4 2.5h3.9l.4-2.5a8 8 0 0 0 2-1.2l2.3 1 2-3.4-2-1.5c.1-.4.1-.8.1-1.2Z"/>',
            'admin'     => '<path d="M12 2 4 5v6c0 5 3.4 9.4 8 11 4.6-1.6 8-6 8-11V5Zm0 4.5 1.5 3.2 3.5.4-2.6 2.4.7 3.5-3.1-1.8-3.1 1.8.7-3.5L7 10.1l3.5-.4Z"/>',
            'plus'      => '<path d="M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6Z"/>',
            'play'      => '<path d="M8 5v14l11-7Z"/>',
            'pause'     => '<path d="M7 5h4v14H7Zm6 0h4v14h-4Z"/>',
            'refresh'   => '<path d="M12 5V2L7 6l5 4V7a5 5 0 1 1-5 5H5a7 7 0 1 0 7-7Z"/>',
            'check'     => '<path d="M9.6 16.2 5.4 12l-1.4 1.4 5.6 5.6L20.4 8 19 6.6Z"/>',
            'x'         => '<path d="m12 10.6 5-5L18.4 7l-5 5 5 5L17 18.4l-5-5-5 5L5.6 17l5-5-5-5L7 5.6Z"/>',
            'alert'     => '<path d="M12 2 1 21h22Zm0 6 1 8h-2Zm0 10.5a1.25 1.25 0 1 1 0 2.5 1.25 1.25 0 0 1 0-2.5Z"/>',
            'link'      => '<path d="M10.6 13.4a1 1 0 0 1 0-1.4l1.4-1.4a3 3 0 0 1 4.2 0l1.4 1.4a3 3 0 0 1 0 4.2l-2.1 2.1a3 3 0 0 1-4.2 0l-.7-.7 1.4-1.4.7.7a1 1 0 0 0 1.4 0l2.1-2.1a1 1 0 0 0 0-1.4l-1.4-1.4a1 1 0 0 0-1.4 0l-1.4 1.4Z"/><path d="M13.4 10.6a1 1 0 0 1 0 1.4L12 13.4a3 3 0 0 1-4.2 0L6.4 12a3 3 0 0 1 0-4.2l2.1-2.1a3 3 0 0 1 4.2 0l.7.7-1.4 1.4-.7-.7a1 1 0 0 0-1.4 0L7.8 9.2a1 1 0 0 0 0 1.4l1.4 1.4a1 1 0 0 0 1.4 0Z"/>',
            'external'  => '<path d="M14 3h7v7h-2V6.4l-8.3 8.3-1.4-1.4L17.6 5H14Zm-9 3h6v2H7v9h9v-4h2v6H5Z"/>',
            'download'  => '<path d="M12 3v10.6l3.3-3.3 1.4 1.4L12 17.4l-4.7-4.7 1.4-1.4L12 13.6V3ZM5 19h14v2H5Z"/>',
            'search'    => '<path d="M10 3a7 7 0 1 0 4.2 12.6l4.1 4.1 1.4-1.4-4.1-4.1A7 7 0 0 0 10 3Zm0 2a5 5 0 1 1 0 10 5 5 0 0 1 0-10Z"/>',
            'clock'     => '<path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2Zm0 2a8 8 0 1 1-8 8 8 8 0 0 1 8-8Zm-1 3v6l5 2 .8-1.7L13 11.5V7Z"/>',
            'image'     => '<path d="M4 5h16v14H4Zm2 2v8l3.5-4 2.5 3 3-4 3 5V7Z"/>',
            'video'     => '<path d="M4 6h11v12H4Zm13 3 4-2v10l-4-2Z"/>',
            'shield'    => '<path d="M12 2 4 5v6c0 5 3.4 9.4 8 11 4.6-1.6 8-6 8-11V5Z"/>',
            'tray'      => '<path d="M4 4h16v10H4Zm0 12h4a1 1 0 0 1 1 1h6a1 1 0 0 1 1-1h4v4H4Z"/>',
            'trash'     => '<path d="M9 3h6l1 2h4v2H4V5h4Zm-3 6h12l-1 12H7Z"/>',
            'logout'    => '<path d="M10 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6v-2h6V5h-6Zm-1.4 5.6L10 10.4 8.4 12H15v2H8.4L10 15.6 8.6 17 4.6 13Z"/>',
            'eye'       => '<path d="M12 5c-5 0-9 4.5-9 7s4 7 9 7 9-4.5 9-7-4-7-9-7Zm0 3a4 4 0 1 1 0 8 4 4 0 0 1 0-8Z"/>',
        ];

        $path = $paths[$name] ?? $paths['dashboard'];
        return '<svg class="icon" viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="currentColor" aria-hidden="true">' . $path . '</svg>';
    }

    /** Colored status pill for job/post/page/worker states. */
    public static function status(string $status): string
    {
        $status = strtoupper($status);
        $tone = self::statusTone($status);
        $label = ucwords(strtolower(str_replace('_', ' ', $status)));
        return '<span class="pill pill--' . $tone . '">' . Support::e($label) . '</span>';
    }

    public static function statusTone(string $status): string
    {
        return match (strtoupper($status)) {
            'PUBLISHED', 'CONNECTED', 'ONLINE', 'ENABLED', 'PROBED', 'BUSY' => 'ok',
            'QUEUED', 'SCHEDULED', 'CLAIMED', 'PENDING', 'STARTING', 'INSTALLING', 'UPDATING' => 'info',
            'PROCESSING', 'UPLOADING', 'PUBLISHING', 'VERIFYING', 'RETRYING' => 'active',
            'PAUSED', 'DISABLED', 'DISCONNECTED', 'OFFLINE', 'PARTIAL', 'DRAFT' => 'muted',
            'FAILED', 'ERROR', 'CANCELLED', 'SUSPENDED' => 'bad',
            'USER_ACTION_REQUIRED', 'ACCOUNT_REAUTH_REQUIRED', 'CHALLENGE_REQUIRED', 'AUTH_REQUIRED' => 'warn',
            default => 'muted',
        };
    }

    /** "3 minutes ago" style relative time from a UTC storage timestamp. */
    public static function ago(?string $utc): string
    {
        $ts = Clock::parseUtc($utc)?->getTimestamp();
        if ($ts === null) {
            return 'never';
        }
        $delta = time() - $ts;
        if ($delta < 0) {
            return 'in ' . self::humanSeconds(-$delta);
        }
        if ($delta < 10) {
            return 'just now';
        }
        return self::humanSeconds($delta) . ' ago';
    }

    /** Countdown style relative time: "in 4 minutes". */
    public static function until(?string $utc): string
    {
        $seconds = Clock::secondsUntil($utc);
        if ($seconds === PHP_INT_MAX) {
            return 'unscheduled';
        }
        return $seconds <= 0 ? 'due now' : 'in ' . self::humanSeconds($seconds);
    }

    public static function humanSeconds(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60) . 'm';
        }
        if ($seconds < 86400) {
            $h = intdiv($seconds, 3600);
            $m = intdiv($seconds % 3600, 60);
            return $m > 0 ? "{$h}h {$m}m" : "{$h}h";
        }
        $d = intdiv($seconds, 86400);
        $h = intdiv($seconds % 86400, 3600);
        return $h > 0 ? "{$d}d {$h}h" : "{$d}d";
    }

    /** Duration for video metadata display. */
    public static function duration(?float $seconds): string
    {
        if ($seconds === null || $seconds <= 0) {
            return '—';
        }
        $total = (int) round($seconds);
        return sprintf('%d:%02d', intdiv($total, 60), $total % 60);
    }

    /** Local-time render from a UTC storage value, in the viewer's timezone. */
    public static function localTime(?string $utc, string $timezone, string $format = 'M j, Y H:i'): string
    {
        return Clock::utcToLocal($utc, $timezone, $format) ?? '—';
    }

    public static function progress(int $percent, string $tone = 'info'): string
    {
        $percent = max(0, min(100, $percent));
        return '<span class="progress" role="progressbar" aria-valuenow="' . $percent . '" aria-valuemin="0" aria-valuemax="100">'
            . '<span class="progress__bar progress__bar--' . Support::e($tone) . '" style="width:' . $percent . '%"></span></span>';
    }

    public static function emptyState(string $title, string $hint = '', string $icon = 'posts', ?string $actionUrl = null, ?string $actionLabel = null): string
    {
        $html = '<div class="empty">' . self::icon($icon, 28)
            . '<p class="empty__title">' . Support::e($title) . '</p>';
        if ($hint !== '') {
            $html .= '<p class="empty__hint">' . Support::e($hint) . '</p>';
        }
        if ($actionUrl !== null && $actionLabel !== null) {
            $html .= '<a class="btn btn--primary" href="' . Support::e($actionUrl) . '">' . Support::e($actionLabel) . '</a>';
        }
        return $html . '</div>';
    }

    /** Tiny inline sparkline/bars for throughput without any JS library. */
    public static function barChart(array $series, string $publishedKey = 'published', string $failedKey = 'failed'): string
    {
        $max = 1;
        foreach ($series as $point) {
            $max = max($max, (int) ($point[$publishedKey] ?? 0) + (int) ($point[$failedKey] ?? 0));
        }

        $bars = '';
        foreach ($series as $point) {
            $published = (int) ($point[$publishedKey] ?? 0);
            $failed = (int) ($point[$failedKey] ?? 0);
            $height = static fn (int $value): int => $value === 0 ? 0 : max(4, (int) round(($value / $max) * 100));
            $bars .= '<span class="chart__col" title="' . Support::e((string) ($point['day'] ?? '')) . ': ' . $published . ' published, ' . $failed . ' failed">'
                . '<span class="chart__bar chart__bar--ok" style="height:' . $height($published) . '%"></span>'
                . '<span class="chart__bar chart__bar--bad" style="height:' . $height($failed) . '%"></span>'
                . '</span>';
        }

        return '<span class="chart">' . $bars . '</span>';
    }

    /** Human label for a job type or error code. */
    public static function humanize(string $value): string
    {
        return ucwords(strtolower(str_replace('_', ' ', $value)));
    }
}
