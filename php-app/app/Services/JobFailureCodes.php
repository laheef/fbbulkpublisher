<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Canonical failure taxonomy shared by the worker, the API and the UI.
 *
 * retryable  = transient; the queue will retry with backoff up to max_attempts
 * userAction = automation pauses and a human must intervene in the browser
 * permanent  = never retried automatically (would just fail again)
 */
final class JobFailureCodes
{
    public const RETRYABLE = [
        'NETWORK_ERROR',
        'SERVER_UNREACHABLE',
        'BROWSER_CRASHED',
        'BROWSER_LAUNCH_FAILED',
        'UPLOAD_INTERRUPTED',
        'TIMEOUT',
        'PAGE_LOAD_TIMEOUT',
        'MEDIA_DOWNLOAD_FAILED',
        'WORKER_RESTARTED',
        'LEASE_EXPIRED',
        'RATE_LIMITED',
        'UNKNOWN_TRANSIENT',
    ];

    public const USER_ACTION = [
        'CAPTCHA_DETECTED',
        'SECURITY_CHALLENGE',
        'CHECKPOINT',
        '2FA_REQUIRED',
        'IDENTITY_VERIFICATION',
        'ACCOUNT_REAUTH_REQUIRED',
        'PROFILE_LOCKED',
    ];

    public const PERMANENT = [
        'FACEBOOK_UI_CHANGED',
        'PUBLISH_VERIFICATION_REQUIRED',
        'ACCOUNT_DISABLED',
        'PAGE_NOT_FOUND',
        'PAGE_NO_PERMISSION',
        'MEDIA_INVALID',
        'MEDIA_MISSING',
        'POST_EMPTY',
        'UNSUPPORTED_MEDIA',
        'JOB_CANCELLED',
        'DUPLICATE_PUBLICATION_CONFIRMED',
        'PERMANENT_PLATFORM_REJECTION',
    ];

    private const LABELS = [
        'NETWORK_ERROR'                => 'Network connection failed',
        'SERVER_UNREACHABLE'           => 'LinkEasy server unreachable',
        'BROWSER_CRASHED'              => 'The browser crashed',
        'BROWSER_LAUNCH_FAILED'        => 'The browser could not be started',
        'UPLOAD_INTERRUPTED'           => 'The upload was interrupted',
        'TIMEOUT'                      => 'The operation timed out',
        'PAGE_LOAD_TIMEOUT'            => 'A Facebook page did not finish loading',
        'MEDIA_DOWNLOAD_FAILED'        => 'The media file could not be downloaded',
        'WORKER_RESTARTED'             => 'The worker restarted mid-job',
        'LEASE_EXPIRED'                => 'The job lease expired',
        'RATE_LIMITED'                 => 'Requests are being rate limited',
        'UNKNOWN_TRANSIENT'            => 'A temporary error occurred',
        'CAPTCHA_DETECTED'             => 'Facebook presented a CAPTCHA',
        'SECURITY_CHALLENGE'           => 'Facebook requested a security check',
        'CHECKPOINT'                   => 'Facebook raised a checkpoint',
        '2FA_REQUIRED'                 => 'Facebook requested two-factor confirmation',
        'IDENTITY_VERIFICATION'        => 'Facebook requested identity verification',
        'ACCOUNT_REAUTH_REQUIRED'      => 'The Facebook session has expired',
        'PROFILE_LOCKED'               => 'The browser profile is locked',
        'FACEBOOK_UI_CHANGED'          => 'Facebook changed its interface; selectors need review',
        'PUBLISH_VERIFICATION_REQUIRED' => 'The post may have published but could not be verified',
        'ACCOUNT_DISABLED'             => 'The Facebook account is not available',
        'PAGE_NOT_FOUND'               => 'The Facebook Page could not be found',
        'PAGE_NO_PERMISSION'           => 'This account cannot post to that Page',
        'MEDIA_INVALID'                => 'The media file is not valid',
        'MEDIA_MISSING'                => 'The media file is missing',
        'POST_EMPTY'                   => 'The post has no content',
        'UNSUPPORTED_MEDIA'            => 'That media type is not supported',
        'JOB_CANCELLED'                => 'The job was cancelled',
        'DUPLICATE_PUBLICATION_CONFIRMED' => 'A previous attempt already published this content',
        'PERMANENT_PLATFORM_REJECTION' => 'Facebook rejected the publication',
    ];

    public static function isRetryable(string $code): bool
    {
        return in_array($code, self::RETRYABLE, true);
    }

    public static function requiresUserAction(string $code): bool
    {
        return in_array($code, self::USER_ACTION, true);
    }

    public static function isPermanent(string $code): bool
    {
        return in_array($code, self::PERMANENT, true);
    }

    public static function label(string $code): string
    {
        return self::LABELS[$code] ?? str_replace('_', ' ', $code);
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_merge(self::RETRYABLE, self::USER_ACTION, self::PERMANENT);
    }
}
