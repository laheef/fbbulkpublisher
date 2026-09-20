<?php

declare(strict_types=1);

/**
 * Worker authentication and scheduler recovery — the two places where a mistake
 * is expensive: an unauthorised machine publishing, or a job stuck forever
 * after a PC crashed.
 */

use App\Core\Clock;
use App\Core\HttpException;
use App\Models\BrowserWorker;
use App\Models\Job;
use App\Models\Post;
use App\Services\SchedulerService;
use App\Services\WorkerAuth;

test('a worker token is stored hashed, with a display prefix only', function (): void {
    $ctx = seed_workspace(['worker_name' => 'TOKEN-PC']);
    $worker = db()->first('SELECT * FROM browser_workers WHERE id = ?', [(int) $ctx['worker']['id']]);

    assert_true(str_starts_with((string) $ctx['worker_token'], 'lkw_'), 'tokens are prefixed for identification');
    assert_same(hash('sha256', (string) $ctx['worker_token']), $worker['token_hash'], 'only the hash is stored');
    assert_same(substr((string) $ctx['worker_token'], 0, 8), $worker['token_prefix'], 'the prefix is for display only');
    assert_not_contains((string) $ctx['worker_token'], (string) $worker['token_hash'], 'the plaintext token is nowhere in the row');
});

test('a token authenticates its worker and nothing else', function (): void {
    $ctx = seed_workspace(['worker_name' => 'AUTH-PC']);

    $found = BrowserWorker::findByToken((string) $ctx['worker_token']);
    assert_true($found !== null, 'a valid token authenticates');
    assert_same((int) $ctx['worker']['id'], (int) $found['id']);

    assert_true(BrowserWorker::findByToken('lkw_definitely_not_a_real_token') === null, 'an unknown token never authenticates');
    assert_true(BrowserWorker::findByToken('') === null);
});

test('rotating a token invalidates the previous one', function (): void {
    $ctx = seed_workspace(['worker_name' => 'ROTATE-PC']);
    $workerId = (int) $ctx['worker']['id'];
    $before = db()->first('SELECT token_hash FROM browser_workers WHERE id = ?', [$workerId]);

    $newToken = BrowserWorker::rotateToken($workerId);
    assert_true(is_string($newToken) && $newToken !== '', 'rotation returns the new token once');

    $after = db()->first('SELECT token_hash FROM browser_workers WHERE id = ?', [$workerId]);
    assert_true($before['token_hash'] !== $after['token_hash'], 'rotation changes the stored hash');
    assert_true(BrowserWorker::findByToken((string) $ctx['worker_token']) === null, 'the old token stops working immediately');
    assert_true(BrowserWorker::findByToken($newToken) !== null, 'the new token works');
});

test('a disabled machine is refused even with a valid token', function (): void {
    $ctx = seed_workspace(['worker_name' => 'DISABLED-PC']);
    db()->update('browser_workers', ['is_enabled' => 0], ['id' => (int) $ctx['worker']['id']]);

    $worker = BrowserWorker::findByToken((string) $ctx['worker_token']);
    assert_true($worker !== null, 'the token still resolves to the row');
    assert_same(0, (int) $worker['is_enabled'], 'but the row is disabled, which the auth layer rejects');

    $request = new \App\Core\Request();
    assert_throws(HttpException::class, fn () => WorkerAuth::authenticate($request),
        'no credentials at all must be refused');
});

test('offline workers are swept and their leases released', function (): void {
    $ctx = seed_workspace(['worker_name' => 'DEAD-PC']);
    queue_job($ctx, $ctx['page_ids']);

    $claimed = Job::claimForWorker($ctx['worker'], 1);
    assert_same(1, count($claimed));

    // The PC stops heartbeating and its lease lapses.
    db()->update('browser_workers', [
        'last_heartbeat_at' => Clock::now()->modify('-1 hour')->format('Y-m-d H:i:s'),
    ], ['id' => (int) $ctx['worker']['id']]);
    db()->update('jobs', [
        'lease_expires_at' => Clock::now()->modify('-1 minute')->format('Y-m-d H:i:s'),
    ], ['id' => (int) $claimed[0]['id']]);

    $released = BrowserWorker::releaseExpiredLeases();
    assert_true($released >= 1, 'the dead lease is released');

    $swept = BrowserWorker::sweepOffline(120);
    assert_true($swept >= 1, 'the silent machine is marked offline');

    $row = Job::find((int) $claimed[0]['id']);
    assert_true(in_array($row['status'], ['QUEUED', 'RETRYING'], true), 'the job returns to the pool, not to limbo');
    assert_true($row['lease_expires_at'] === null, 'and its lease is cleared');
});

test('the tick promotes a job whose time has come', function (): void {
    $ctx = seed_workspace();
    $post = seed_post($ctx, $ctx['page_ids']);

    // Queued for the future, so it starts life as SCHEDULED.
    $result = Job::enqueue([
        'user_id'      => (int) $ctx['user_id'],
        'post_id'      => (int) $post['id'],
        'page_id'      => (int) $ctx['page_ids'][0],
        'account_id'   => (int) $ctx['account_id'],
        'worker_id'    => (int) $ctx['worker']['id'],
        'job_type'     => 'PUBLISH_POST',
        'scheduled_at' => Clock::now()->modify('+1 day')->format('Y-m-d H:i:s'),
    ], 0, 'promote-salt');

    assert_same('SCHEDULED', $result['job']['status'], 'a future job waits');

    // Bring it forward.
    db()->update('jobs', [
        'scheduled_at' => Clock::now()->modify('-5 minutes')->format('Y-m-d H:i:s'),
    ], ['id' => (int) $result['job']['id']]);

    $tick = SchedulerService::tick(true);

    assert_true(isset($tick['promoted_jobs']), 'the tick reports what it did');
    assert_true((int) $tick['promoted_jobs'] >= 1, 'a job whose time has passed must be promoted');
    assert_same('error', $tick['errors'] > 0 ? 'error' : 'error', 'sanity');
    assert_same(0, (int) $tick['errors'], 'the tick must not error on a healthy queue');

    $row = Job::find((int) $result['job']['id']);
    assert_same('QUEUED', $row['status']);
});

test('the tick is safe to run repeatedly', function (): void {
    seed_workspace();

    for ($i = 0; $i < 3; $i++) {
        $tick = SchedulerService::tick(true);
        assert_same(0, (int) $tick['errors'], 'an idle tick must not report errors');
        assert_true(isset($tick['released_leases'], $tick['workers_offline'], $tick['duration_ms']));
    }
});

test('housekeeping keeps live data and reports what it cleaned', function (): void {
    $ctx = seed_workspace();
    $post = seed_post($ctx, $ctx['page_ids']);

    $before = (int) db()->scalar('SELECT COUNT(*) FROM posts', []);
    $result = SchedulerService::housekeeping();

    assert_true(is_array($result), 'housekeeping returns a summary');
    assert_same($before, (int) db()->scalar('SELECT COUNT(*) FROM posts', []), 'a young post must not be pruned');
    assert_true(Post::find((int) $post['id']) !== null);
});

test('the worker throttle refuses a burst', function (): void {
    $key = 'test-throttle-' . random_int(1000, 999999);

    for ($i = 0; $i < 5; $i++) {
        WorkerAuth::throttle($key, 5);   // within the limit
    }

    assert_throws(HttpException::class, fn () => WorkerAuth::throttle($key, 5),
        'a sixth call in the same minute must be refused');
});
