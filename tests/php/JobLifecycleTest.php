<?php

declare(strict_types=1);

/**
 * The queue contract: atomic claims, leases, retry caps, honest completion, and
 * the isolation rules that keep one tenant out of another tenant's work.
 */

use App\Core\Clock;
use App\Core\HttpException;
use App\Models\Job;
use App\Services\JobFailureCodes;

test('a queued job can be claimed exactly once', function (): void {
    $ctx = seed_workspace();
    $job = queue_job($ctx, $ctx['page_ids']);

    $claimed = Job::claimForWorker($ctx['worker'], 5);
    assert_same(1, count($claimed), 'the worker should receive the queued job');
    assert_same((int) $job['id'], (int) $claimed[0]['id']);
    assert_same('CLAIMED', $claimed[0]['status']);
    assert_true($claimed[0]['lease_expires_at'] !== null, 'a claimed job carries a lease');

    $again = Job::claimForWorker($ctx['worker'], 5);
    assert_same(0, count($again), 'a leased job must not be handed out twice');
});

test('re-queueing the same post and Page for the same run is refused', function (): void {
    $ctx = seed_workspace();
    $pageIds = $ctx['page_ids'];
    $post = seed_post($ctx, $pageIds);

    $attributes = [
        'user_id'      => (int) $ctx['user_id'],
        'post_id'      => (int) $post['id'],
        'page_id'      => (int) $pageIds[0],
        'account_id'   => (int) $ctx['account_id'],
        'worker_id'    => (int) $ctx['worker']['id'],
        'job_type'     => 'PUBLISH_POST',
        'scheduled_at' => Clock::now()->modify('-1 minute')->format('Y-m-d H:i:s'),
    ];

    $first = Job::enqueue($attributes, 0, 'same-salt');
    assert_same(true, $first['created'], 'the first enqueue creates the job');

    // The idempotency key is the whole guarantee: the same post × page × run
    // can never become two jobs, so a retry can never become two posts.
    $second = Job::enqueue($attributes, 0, 'same-salt');
    assert_same(false, $second['created'], 'the second enqueue must be recognised as a duplicate');
    assert_same((int) $first['job']['id'], (int) $second['job']['id'], 'and must return the existing job');
    assert_same(1, (int) db()->scalar('SELECT COUNT(*) FROM jobs', []), 'no second row may be created');

    // A different run index is a genuinely different job (a recurrence).
    $third = Job::enqueue($attributes, 1, 'same-salt');
    assert_same(true, $third['created']);
    assert_same(2, (int) db()->scalar('SELECT COUNT(*) FROM jobs', []));
});

test('an expired lease lets the job be claimed again', function (): void {
    $ctx = seed_workspace();
    queue_job($ctx, $ctx['page_ids']);

    $claimed = Job::claimForWorker($ctx['worker'], 1);
    assert_same(1, count($claimed));

    // Simulate the PC dying mid-job: its lease lapses.
    db()->update('jobs', ['lease_expires_at' => '2000-01-01 00:00:00'], ['id' => (int) $claimed[0]['id']]);

    // A job whose lease is dead is released back to the pool by the scheduler —
    // that is the production recovery path (cron/tick), not an implicit claim.
    $released = \App\Models\BrowserWorker::releaseExpiredLeases();
    assert_true($released >= 1, 'the scheduler releases dead leases');

    $tick = \App\Services\SchedulerService::tick(true);
    assert_true((int) $tick['released_leases'] >= 0);

    $recovered = Job::claimForWorker($ctx['worker'], 1);
    assert_same(1, count($recovered), 'a job with a dead lease must become claimable again');
    assert_same(2, (int) $recovered[0]['attempts'], 'the attempt counter increments on re-claim');
});

test('a success report without evidence is refused', function (): void {
    $ctx = seed_workspace();
    $job = queue_job($ctx, $ctx['page_ids']);
    Job::claimForWorker($ctx['worker'], 1);

    assert_throws(
        HttpException::class,
        fn () => Job::complete((int) $job['id'], ['verified' => false]),
        'the model must refuse an unverified success'
    );

    $still = Job::find((int) $job['id']);
    assert_same('CLAIMED', $still['status'], 'a refused completion must not change the job');
    assert_true($still['result_url'] === null || $still['result_url'] === '', 'and must not record a URL');
});

test('a verified completion publishes the job and is idempotent', function (): void {
    $ctx = seed_workspace();
    $job = queue_job($ctx, $ctx['page_ids']);
    Job::claimForWorker($ctx['worker'], 1);

    $url = 'https://www.facebook.com/test-page-0/posts/123';
    assert_true(Job::complete((int) $job['id'], ['result_url' => $url, 'verified' => true]));

    $published = Job::find((int) $job['id']);
    assert_same('PUBLISHED', $published['status']);
    assert_same($url, $published['result_url']);
    assert_true($published['completed_at'] !== null, 'the completion is timestamped');

    // Reporting the same completion again must be harmless.
    Job::complete((int) $job['id'], ['result_url' => $url, 'verified' => true]);
    $again = Job::find((int) $job['id']);
    assert_same('PUBLISHED', $again['status']);
    assert_same($url, $again['result_url']);

    assert_same(1, (int) db()->scalar('SELECT COUNT(*) FROM jobs', []), 'a duplicate report must never create a second job');
});

test('a retryable failure is rescheduled with backoff, then gives up at the cap', function (): void {
    $ctx = seed_workspace();
    $job = queue_job($ctx, $ctx['page_ids']);

    $maxAttempts = (int) $job['max_attempts'];
    assert_same(3, $maxAttempts, 'the retry cap is three attempts in total');

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $claimed = Job::claimForWorker($ctx['worker'], 1);
        assert_same(1, count($claimed), "attempt {$attempt} should be claimable");

        Job::fail((int) $job['id'], [
            'error_code'    => 'NETWORK_ERROR',
            'error_message' => 'connection reset',
            'retryable'     => true,
        ]);

        $row = Job::find((int) $job['id']);

        if ($attempt < $maxAttempts) {
            assert_same('RETRYING', $row['status'], "attempt {$attempt} should be retried, not failed");
            assert_true($row['next_attempt_at'] !== null, 'a retry must be scheduled');

            // Let the backoff elapse so the next attempt can be claimed.
            db()->update('jobs', [
                'next_attempt_at'  => Clock::now()->modify('-1 minute')->format('Y-m-d H:i:s'),
                'lease_expires_at' => null,
            ], ['id' => (int) $job['id']]);
        } else {
            assert_same('FAILED', $row['status'], 'the final failure must stop, not retry forever');
        }
    }
});

test('a permanent error stops immediately', function (): void {
    $ctx = seed_workspace();
    $job = queue_job($ctx, $ctx['page_ids']);
    Job::claimForWorker($ctx['worker'], 1);

    Job::fail((int) $job['id'], [
        'error_code'    => 'FACEBOOK_UI_CHANGED',
        'error_message' => 'The composer was not found.',
        'retryable'     => true,   // even if the worker claims it is retryable
    ]);

    $row = Job::find((int) $job['id']);
    assert_same('FAILED', $row['status'], 'a permanent code must never be retried');
    assert_same('FACEBOOK_UI_CHANGED', $row['error_code']);

    // The next poll must not pick it up again.
    assert_same(0, count(Job::claimForWorker($ctx['worker'], 5)));
    assert_true(JobFailureCodes::isPermanent('FACEBOOK_UI_CHANGED'));
});

test('a security challenge pauses the job for a human', function (): void {
    $ctx = seed_workspace();
    $job = queue_job($ctx, $ctx['page_ids']);
    Job::claimForWorker($ctx['worker'], 1);

    Job::fail((int) $job['id'], [
        'error_code'    => 'CAPTCHA_DETECTED',
        'error_message' => 'Facebook presented a CAPTCHA.',
        'user_action_required' => true,
    ]);

    $row = Job::find((int) $job['id']);
    assert_same('USER_ACTION_REQUIRED', $row['status']);
    assert_same('waiting_for_user', $row['stage']);
    assert_same('CAPTCHA_DETECTED', $row['error_code']);

    $account = db()->first('SELECT status FROM facebook_accounts WHERE id = ?', [(int) $ctx['account_id']]);
    assert_same('CHALLENGE_REQUIRED', $account['status'], 'the account is marked until the check is cleared');

    // The job must not be claimable while it waits for a human.
    assert_same(0, count(Job::claimForWorker($ctx['worker'], 5)));

    assert_true(count(\App\Models\Notification::forUser((int) $ctx['user_id'])) >= 1, 'the operator is notified');
});

test('a reauthentication failure is reported as its own state', function (): void {
    $ctx = seed_workspace();
    $job = queue_job($ctx, $ctx['page_ids']);
    Job::claimForWorker($ctx['worker'], 1);

    Job::fail((int) $job['id'], [
        'error_code' => 'ACCOUNT_REAUTH_REQUIRED',
        'error_message' => 'The Facebook session expired.',
        'user_action_required' => true,
    ]);

    $row = Job::find((int) $job['id']);
    assert_same('ACCOUNT_REAUTH_REQUIRED', $row['status']);
    $account = db()->first('SELECT status FROM facebook_accounts WHERE id = ?', [(int) $ctx['account_id']]);
    assert_same('AUTH_REQUIRED', $account['status']);
});

test('one tenant cannot see or touch another tenant\'s jobs', function (): void {
    $first = seed_workspace(['email' => 'first@example.test']);
    $second = seed_workspace(['email' => 'second@example.test', 'worker_name' => 'OTHER-PC']);

    $job = queue_job($first, $first['page_ids']);

    assert_same(0, count(Job::forUser((int) $second['user_id'], [], 50, 0)), 'a second workspace must not see the first workspace\'s jobs');
    assert_same(1, count(Job::forUser((int) $first['user_id'], [], 50, 0)), 'the owner sees their own job');

    // The second worker must not be handed the first workspace's job.
    assert_same(0, count(Job::claimForWorker($second['worker'], 5)), 'claims are scoped to the worker\'s workspace');
});

test('cancelling a job is terminal', function (): void {
    $ctx = seed_workspace();
    $job = queue_job($ctx, $ctx['page_ids']);

    assert_true(Job::cancel((int) $job['id'], 'operator'));
    $row = Job::find((int) $job['id']);
    assert_same('CANCELLED', $row['status']);

    assert_same(0, count(Job::claimForWorker($ctx['worker'], 5)), 'a cancelled job must never be claimed');
});

test('every state change is written to the job event trail', function (): void {
    $ctx = seed_workspace();
    $job = queue_job($ctx, $ctx['page_ids']);
    Job::claimForWorker($ctx['worker'], 1);

    $events = db()->select('SELECT * FROM job_events WHERE job_id = ? ORDER BY id', [(int) $job['id']]);
    assert_true(count($events) >= 2, 'enqueue and claim should both leave an event');

    $states = array_column($events, 'to_state');
    assert_true(in_array('QUEUED', $states, true), 'the enqueue is recorded');
    assert_true(in_array('CLAIMED', $states, true), 'the claim is recorded');
});

test('a post rolls up to published only when every Page succeeded', function (): void {
    $ctx = seed_workspace();
    $pageIds = $ctx['page_ids'];
    $post = seed_post($ctx, $pageIds);

    $jobIds = [];
    foreach ($pageIds as $pageId) {
        $result = Job::enqueue([
            'user_id'      => (int) $ctx['user_id'],
            'post_id'      => (int) $post['id'],
            'page_id'      => (int) $pageId,
            'account_id'   => (int) $ctx['account_id'],
            'worker_id'    => (int) $ctx['worker']['id'],
            'job_type'     => 'PUBLISH_POST',
            'scheduled_at' => Clock::now()->modify('-1 minute')->format('Y-m-d H:i:s'),
        ], 0, 'rollup-salt');
        $jobIds[] = (int) $result['job']['id'];
    }

    assert_same(2, count($jobIds));

    Job::claimForWorker($ctx['worker'], 2);
    Job::complete($jobIds[0], ['result_url' => 'https://www.facebook.com/test-page-0/posts/1', 'verified' => true]);

    $partial = \App\Models\Post::find((int) $post['id']);
    assert_true($partial['status'] !== 'PUBLISHED', 'one Page of two is not a published post');
    assert_true(in_array($partial['status'], ['PUBLISHING', 'QUEUED', 'PROCESSING'], true), 'it reports as still working: ' . $partial['status']);

    Job::complete($jobIds[1], ['result_url' => 'https://www.facebook.com/test-page-1/posts/2', 'verified' => true]);
    \App\Models\Post::rollUpStatus((int) $post['id']);

    $done = \App\Models\Post::find((int) $post['id']);
    assert_same('PUBLISHED', $done['status'], 'once every Page succeeded, the post is published');
});
