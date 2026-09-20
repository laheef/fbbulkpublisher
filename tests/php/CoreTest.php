<?php

declare(strict_types=1);

/**
 * Core helpers: identifier generation, redaction and the small utilities the
 * rest of the application depends on.
 */

use App\Core\Support;
use App\Core\Ui;
use App\Services\JobFailureCodes;

test('the idempotency key is stable and unique per post, page and run', function (): void {
    $a = Support::idempotencyKey(10, 20, 0, 'salt');
    $b = Support::idempotencyKey(10, 20, 0, 'salt');
    $c = Support::idempotencyKey(10, 20, 1, 'salt');
    $d = Support::idempotencyKey(10, 21, 0, 'salt');

    assert_same($a, $b, 'the same inputs must always produce the same key, or retries would duplicate posts');
    assert_true($a !== $c, 'a different run index must produce a different key');
    assert_true($a !== $d, 'a different Page must produce a different key');
    assert_same(64, strlen($a), 'the key is a sha256 hex digest');
});

test('tokens are random, prefixed and not guessable', function (): void {
    $first = Support::token(32);
    $second = Support::token(32);

    assert_true($first !== $second);
    assert_true(strlen($first) >= 32);
    assert_same(1, preg_match('/^[A-Za-z0-9\-_]+$/', $first), 'tokens use a URL-safe alphabet');
    assert_true(Support::safeEquals($first, $first));
    assert_true(!Support::safeEquals($first, $second));
});

test('redaction removes cookies, tokens and passwords', function (): void {
    $dirty = 'login c_user=100012345678901; xs=12%3Aabc; datr=ABCdef123 '
        . 'password=hunter2 Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.payload.sig '
        . 'access_token=EAAG1234 token=lkw_abcdefghijklmnop';

    $clean = Support::redact($dirty);

    $dirty .= ' access_token=EAAG1234 refresh_token=zzz client_secret=abc123 api_key=sk-live-1';
    $clean = Support::redact($dirty);

    foreach (['100012345678901', 'ABCdef123', 'hunter2', 'eyJhbGciOiJIUzI1NiJ9', 'EAAG1234', 'zzz', 'abc123', 'sk-live-1', 'lkw_abcdefghijklmnop'] as $secret) {
        assert_not_contains($secret, $clean, 'secret leaked through redaction');
    }
    assert_contains('redacted', strtolower($clean));
});

test('redaction leaves ordinary text alone', function (): void {
    $line = 'Job 42 PUBLISH_IMAGE for Demo Brand 01 completed in 8.2s';
    assert_same($line, Support::redact($line));
});

test('file names are made safe for storage', function (): void {
    assert_same('evil.php.txt', Support::safeFileName('../../evil.php.txt'));
    assert_true(!str_contains(Support::safeFileName('..\\..\\windows\\system32\\cmd.exe'), '/'), 'no path separators survive');
    assert_true(strlen(Support::safeFileName(str_repeat('a', 500))) <= 190, 'file names are truncated');
});

test('path traversal is refused outright', function (): void {
    assert_throws(InvalidArgumentException::class, fn () => Support::assertSafeRelativePath('../etc/passwd'));
    assert_throws(InvalidArgumentException::class, fn () => Support::assertSafeRelativePath('2026/../../secret.txt'));
    Support::assertSafeRelativePath('2026/09/abc123.png');   // must not throw
});

test('human formatting helpers behave', function (): void {
    assert_same('0 B', Support::bytesToHuman(0));
    assert_same('1 KB', Support::bytesToHuman(1024));
    assert_same('1.5 MB', Support::bytesToHuman(1572864));
    assert_true(str_ends_with(Support::truncate(str_repeat('word ', 40), 40), '…'), 'long text is ellipsised');
    assert_same('short', Support::truncate('short', 40));
});

test('failure codes are classified exactly once', function (): void {
    foreach (array_merge(JobFailureCodes::RETRYABLE, JobFailureCodes::USER_ACTION, JobFailureCodes::PERMANENT) as $code) {
        $matches = (int) JobFailureCodes::isRetryable($code)
            + (int) JobFailureCodes::requiresUserAction($code)
            + (int) JobFailureCodes::isPermanent($code);
        assert_same(1, $matches, "code {$code} must have exactly one classification");
    }

    assert_true(JobFailureCodes::isRetryable('NETWORK_ERROR'));
    assert_true(JobFailureCodes::requiresUserAction('CAPTCHA_DETECTED'));
    assert_true(JobFailureCodes::requiresUserAction('ACCOUNT_REAUTH_REQUIRED'));
    assert_true(JobFailureCodes::isPermanent('FACEBOOK_UI_CHANGED'));
    assert_true(JobFailureCodes::isPermanent('PUBLISH_VERIFICATION_REQUIRED'));
    assert_true(!JobFailureCodes::isRetryable('FACEBOOK_UI_CHANGED'), 'a permanent code must never be retryable');
});

test('status pills map to the documented tones', function (): void {
    assert_same('ok', Ui::statusTone('PUBLISHED'));
    assert_same('ok', Ui::statusTone('CONNECTED'));
    assert_same('info', Ui::statusTone('QUEUED'));
    assert_same('active', Ui::statusTone('UPLOADING'));
    assert_same('warn', Ui::statusTone('USER_ACTION_REQUIRED'));
    assert_same('warn', Ui::statusTone('CHALLENGE_REQUIRED'));
    assert_same('bad', Ui::statusTone('FAILED'));
    assert_same('muted', Ui::statusTone('PAUSED'));
});

test('json helpers never leak a parse error to the caller', function (): void {
    assert_same(['a' => 1], Support::jsonDecode('{"a":1}'));
    assert_same([], Support::jsonDecode('not json at all'));
    assert_same([], Support::jsonDecode(null));
    assert_same('{"a":1}', Support::jsonEncode(['a' => 1]));
});
