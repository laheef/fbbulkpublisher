<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Support;
use App\Models\FacebookAccount;
use App\Models\FacebookPage;
use App\Models\Job;
use App\Models\Media;
use App\Models\Notification;
use App\Models\Post;
use App\Models\User;
use App\Services\Publishing\ProviderFactory;

/**
 * Demo / test data generator (§66).
 *
 * Creates a fully populated tenant — accounts, pages, media, posts, schedules,
 * a registered simulated worker and a spread of jobs in every state — without
 * contacting Facebook and without publishing anything.
 */
final class DemoSeeder
{
    public const DEMO_EMAIL = 'demo@linkeasy.local';
    public const DEMO_PASSWORD = 'Demo-Publishing-2026!';

    /** @return array<string,mixed> */
    public static function seed(bool $reset = false): array
    {
        $db = Database::instance();

        if ($reset) {
            $db->query('DELETE FROM users WHERE email = ?', [self::DEMO_EMAIL]);
        }

        $user = User::findByEmail(self::DEMO_EMAIL);
        if ($user === null) {
            $result = User::register('Demo Operator', self::DEMO_EMAIL, self::DEMO_PASSWORD, 'Asia/Karachi');
            if (!$result['ok']) {
                throw new \RuntimeException('Unable to create the demo user: ' . implode(' ', $result['errors'] ?? []));
            }
            $user = $result['user'];
        }
        $userId = (int) $user['id'];

        $worker = self::seedWorker($userId);
        $account = self::seedAccount($userId, $worker);
        $pageIds = self::seedPages($userId, $account);
        $media = self::seedMedia($userId);
        self::seedPosts($userId, $pageIds, $media);
        self::seedWorkerLogs($userId, $worker);
        self::seedNotifications($userId);

        return [
            'user_id'     => $userId,
            'email'       => self::DEMO_EMAIL,
            'password'    => self::DEMO_PASSWORD,
            'worker_id'   => (int) $worker['id'],
            'account_id'  => (int) $account['id'],
            'pages'       => count($pageIds),
        ];
    }

    /** @return array<string,mixed> */
    private static function seedWorker(int $userId): array
    {
        $existing = Database::instance()->first('SELECT * FROM browser_workers WHERE user_id = ? LIMIT 1', [$userId]);
        if ($existing !== null) {
            Database::instance()->update('browser_workers', [
                'status'            => 'ONLINE',
                'last_heartbeat_at' => Clock::nowString(),
                'connected_at'      => Clock::nowString(),
                'app_version'       => '1.0.0',
                'worker_version'    => '1.0.0',
                'playwright_version' => '1.49.0',
                'browser_version'   => 'Chromium 131.0.6778.33 (simulated)',
                'ffmpeg_version'    => 'ffmpeg 7.1 (simulated)',
                'cpu_pct'           => 7.5,
                'mem_mb'            => 412,
                'pending_count'     => 3,
            ], ['id' => (int) $existing['id']]);
            return \App\Models\BrowserWorker::find((int) $existing['id']) ?? $existing;
        }

        $registered = \App\Models\BrowserWorker::register(
            $userId,
            Support::uuid4(),
            Support::uuid4(),
            'DEMO-WORKSTATION',
            [
                'os'                  => 'Windows 11 Pro 24H2 (simulated)',
                'arch'                => 'x86_64',
                'app_version'         => '1.0.0',
                'worker_version'      => '1.0.0',
                'playwright_version'  => '1.49.0',
                'browser_version'     => 'Chromium 131.0.6778.33 (simulated)',
                'ffmpeg_version'      => 'ffmpeg 7.1 (simulated)',
                'max_concurrent_jobs' => 2,
            ]
        );

        \App\Models\BrowserWorker::heartbeat((int) $registered['worker']['id'], [
            'status' => 'ONLINE', 'cpu_pct' => 7.5, 'mem_mb' => 412,
            'internet_ok' => true, 'pending_count' => 3,
        ]);

        return $registered['worker'];
    }

    /** @return array<string,mixed> */
    private static function seedAccount(int $userId, array $worker): array
    {
        $existing = FacebookAccount::findBy('profile_ref', 'acct_demo_0000000001');
        if ($existing !== null) {
            return $existing;
        }

        $account = FacebookAccount::create([
            'user_id'         => $userId,
            'label'           => 'Demo Business Account',
            'fb_account_name' => 'Demo Operator',
            'fb_account_id'   => '100000000000001',
            'profile_ref'     => 'acct_demo_0000000001',
            'worker_id'       => (int) $worker['id'],
            'status'          => 'CONNECTED',
            'last_verified_at' => Clock::nowString(),
        ]);

        return $account ?? [];
    }

    /** @return list<int> */
    private static function seedPages(int $userId, array $account): array
    {
        $pages = [];
        for ($i = 1; $i <= 6; $i++) {
            $pages[] = [
                'page_id'   => 'demo_page_' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'name'      => 'Demo Brand ' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'url'       => 'https://www.facebook.com/demo_page_' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'category'  => $i % 2 === 0 ? 'Product/Service' : 'Media/News Company',
                'avatar_url' => null,
            ];
        }

        $sync = FacebookPage::syncFromWorker($userId, (int) $account['id'], $pages);

        // One Page deliberately parked in a state that needs a human, so the
        // "Action Required" workflow is visible in the demo.
        if (isset($sync['ids'][4])) {
            Database::instance()->update('facebook_pages', ['status' => 'CHALLENGE_REQUIRED'], ['id' => $sync['ids'][4]]);
        }
        if (isset($sync['ids'][5])) {
            Database::instance()->update('facebook_pages', ['status' => 'DISABLED'], ['id' => $sync['ids'][5]]);
        }

        return $sync['ids'];
    }

    /** @return array<string,mixed> */
    private static function seedMedia(int $userId): array
    {
        $existing = Media::findBy('original_name', 'demo-launch-reel.mp4');
        if ($existing !== null) {
            return ['video' => $existing, 'image' => Media::findBy('original_name', 'demo-launch-cover.png')];
        }

        $base = rtrim(\App\Core\Config::str('uploads.disk_path'), '/');
        $folder = date('Y/m');
        if (!is_dir($base . '/' . $folder)) {
            mkdir($base . '/' . $folder, 0775, true);
        }

        // 1x1 transparent PNG, written directly — no external assets required.
        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $imageName = 'demo-cover-' . bin2hex(random_bytes(6)) . '.png';
        file_put_contents($base . '/' . $folder . '/' . $imageName, $pngBytes);

        $imageId = Database::instance()->insert('media', [
            'user_id'       => $userId,
            'kind'          => 'IMAGE',
            'original_name' => 'demo-launch-cover.png',
            'stored_name'   => $imageName,
            'relative_path' => $folder . '/' . $imageName,
            'mime_type'     => 'image/png',
            'size_bytes'    => strlen($pngBytes),
            'width'         => 1080,
            'height'        => 1350,
            'aspect_ratio'  => '4:5',
            'probe_status'  => 'PROBED',
            'strategy'      => 'SERVER',
        ]);

        // A placeholder video record: metadata only, no binary. The simulator
        // never needs the actual bytes, and nothing is uploaded anywhere.
        $videoId = Database::instance()->insert('media', [
            'user_id'       => $userId,
            'kind'          => 'VIDEO',
            'original_name' => 'demo-launch-reel.mp4',
            'stored_name'   => 'demo-launch-reel.mp4',
            'relative_path' => $folder . '/demo-launch-reel.mp4',
            'mime_type'     => 'video/mp4',
            'size_bytes'    => 48_120_000,
            'width'         => 1080,
            'height'        => 1920,
            'duration_s'    => 27.4,
            'fps'           => 30.0,
            'codec'         => 'h264',
            'aspect_ratio'  => '9:16',
            'probe_status'  => 'PROBED',
            'strategy'      => 'LOCAL',
        ]);

        Logger::info('Demo media seeded', ['image_id' => $imageId, 'video_id' => $videoId]);

        return ['image' => Media::find($imageId), 'video' => Media::find($videoId)];
    }

    /** @param list<int> $pageIds @param array<string,mixed> $media */
    private static function seedPosts(int $userId, array $pageIds, array $media): void
    {
        if (Post::count('user_id = ?', [$userId]) > 0) {
            return;
        }

        $video = $media['video'] ?? null;
        $image = $media['image'] ?? null;

        // 1. A video post that has already published everywhere it targeted.
        $published = Post::compose($userId, [
            'kind'    => 'VIDEO',
            'caption' => "New product walkthrough is live. Five minutes, start to finish, no fluff.\n\nQuestions? Drop them below.",
            'hashtags' => '#launch #walkthrough #newrelease',
            'media_id' => $video['id'] ?? null,
        ], 'Asia/Karachi');
        Post::attachPages((int) $published['id'], $userId, array_slice($pageIds, 0, 3));
        self::publishJobs($userId, (int) $published['id'], array_slice($pageIds, 0, 3), $video, 3);

        // 2. An image post scheduled for the future.
        $scheduled = Post::compose($userId, [
            'kind'     => 'IMAGE',
            'caption'  => "Behind the scenes of this week's shoot.",
            'hashtags' => '#bts #studio',
            'media_id' => $image['id'] ?? null,
        ], 'Asia/Karachi');
        Post::attachPages((int) $scheduled['id'], $userId, array_slice($pageIds, 0, 2));
        self::scheduleJobs($userId, (int) $scheduled['id'], array_slice($pageIds, 0, 2), $image, '+2 days');

        // 3. A text post awaiting a worker.
        $queued = Post::compose($userId, [
            'kind'    => 'TEXT',
            'caption' => "Weekly tip: batch your content on Sunday so publishing is a five-minute job later.",
            'hashtags' => '#socialtips',
        ], 'Asia/Karachi');
        Post::attachPages((int) $queued['id'], $userId, array_slice($pageIds, 0, 2));
        self::queuedJobs($userId, (int) $queued['id'], array_slice($pageIds, 0, 2), null);

        // 4. A post needing the operator's attention (simulated security check).
        $action = Post::compose($userId, [
            'kind'    => 'VIDEO',
            'caption' => 'Trailer drop. Full reel goes live tomorrow.',
            'media_id' => $video['id'] ?? null,
        ], 'Asia/Karachi');
        Post::attachPages((int) $action['id'], $userId, [array_slice($pageIds, 0, 1)[0]]);
        self::actionRequiredJob($userId, (int) $action['id'], array_slice($pageIds, 0, 1)[0], $video);

        // 5. A draft.
        $draft = Post::compose($userId, [
            'kind'    => 'TEXT',
            'caption' => 'Draft: quarterly recap, still waiting on the numbers.',
        ], 'Asia/Karachi');
        Post::attachPages((int) $draft['id'], $userId, array_slice($pageIds, 0, 1));
    }

    /** @param list<int> $pageIds */
    private static function publishJobs(int $userId, int $postId, array $pageIds, ?array $media, int $daysAgo): void
    {
        foreach ($pageIds as $index => $pageId) {
            $page = FacebookPage::find($pageId);
            if ($page === null) {
                continue;
            }
            $when = Clock::now()->modify('-' . $daysAgo . ' days')->modify('+' . ($index * 180) . ' seconds')->format('Y-m-d H:i:s');

            $created = Job::enqueue([
                'user_id'      => $userId,
                'account_id'   => (int) $page['account_id'],
                'page_id'      => $pageId,
                'post_id'      => $postId,
                'media_id'     => $media['id'] ?? null,
                'provider'     => 'SIMULATED',
                'job_type'     => $media !== null ? 'PUBLISH_VIDEO' : 'PUBLISH_POST',
                'scheduled_at' => $when,
                'salt'         => 'demo-published',
            ]);

            Job::complete((int) $created['job']['id'], [
                'result_url' => 'https://www.facebook.com/' . $page['page_id'] . '/posts/demo-' . substr((string) $created['job']['uuid'], 0, 8),
                'meta'       => ['simulated' => true, 'verified' => true],
            ]);
        }
        Post::rollUpStatus($postId);
    }

    /** @param list<int> $pageIds */
    private static function scheduleJobs(int $userId, int $postId, array $pageIds, ?array $media, string $modifier): void
    {
        PostDispatcher::schedule($userId, $postId, [
            'timezone'        => 'Asia/Karachi',
            'scheduled_at_local' => Clock::now()->modify($modifier)->format('Y-m-d H:i:s'),
            'recurrence'      => 'NONE',
            'stagger_seconds' => 120,
        ]);

        Post::setStatus($postId, 'SCHEDULED');
    }

    /** @param list<int> $pageIds */
    private static function queuedJobs(int $userId, int $postId, array $pageIds, ?array $media): void
    {
        foreach ($pageIds as $pageId) {
            $page = FacebookPage::find($pageId);
            if ($page === null || (string) $page['status'] !== 'ENABLED') {
                continue;
            }
            Job::enqueue([
                'user_id'      => $userId,
                'account_id'   => (int) $page['account_id'],
                'page_id'      => $pageId,
                'post_id'      => $postId,
                'media_id'     => $media['id'] ?? null,
                'provider'     => 'SIMULATED',
                'job_type'     => 'PUBLISH_POST',
                'scheduled_at' => Clock::nowString(),
                'salt'         => 'demo-queued',
            ]);
        }
        Post::rollUpStatus($postId);
    }

    private static function actionRequiredJob(int $userId, int $postId, int $pageId, ?array $media): void
    {
        $page = FacebookPage::find($pageId);
        if ($page === null) {
            return;
        }

        $created = Job::enqueue([
            'user_id'      => $userId,
            'account_id'   => (int) $page['account_id'],
            'page_id'      => $pageId,
            'post_id'      => $postId,
            'media_id'     => $media['id'] ?? null,
            'provider'     => 'SIMULATED',
            'job_type'     => 'PUBLISH_VIDEO',
            'scheduled_at' => Clock::now()->modify('-20 minutes')->format('Y-m-d H:i:s'),
            'salt'         => 'demo-challenge',
        ]);

        Job::fail((int) $created['job']['id'], [
            'error_code'          => 'SECURITY_CHALLENGE',
            'error_message'       => 'Facebook asked for a security verification while posting to Demo Brand 01. Open the browser profile on your PC, finish the check, then resume this job.',
            'user_action_required' => true,
            'mark_account'        => false,   // scope the demo interruption to this one Page
            'screenshot_path'     => null,
        ]);

        // Reflect the same interruption on the Page itself, the way the live
        // worker does when Facebook challenges a single Page's composer.
        \App\Core\Database::instance()->update('facebook_pages', ['status' => 'CHALLENGE_REQUIRED'], ['id' => $pageId]);
        Post::rollUpStatus($postId);
    }

    private static function seedWorkerLogs(int $userId, array $worker): void
    {
        if (\App\Models\WorkerLog::count('worker_id = ?', [(int) $worker['id']]) > 0) {
            return;
        }

        $lines = [
            ['app', 'info', 'LinkEasy Publisher service started (demo).'],
            ['app', 'info', 'Runtime verification passed: Node 20.18.0, Playwright 1.49.0, FFmpeg 7.1.'],
            ['worker', 'info', 'Worker registered and heartbeat established.'],
            ['browser', 'info', 'Chromium launched with persistent profile acct_demo_0000000001.'],
            ['scheduler', 'info', 'Claimed 3 due jobs from the central queue.'],
            ['browser', 'info', 'Media uploaded to the Page composer; waiting for processing.'],
            ['app', 'warn', 'Facebook requested a security verification; automation paused pending operator action.'],
            ['browser', 'info', 'Publication verified against the Page timeline; result reported to the server.'],
        ];

        foreach ($lines as $index => [$channel, $level, $message]) {
            \App\Models\WorkerLog::ingest(
                (int) $worker['id'],
                $userId,
                $channel,
                $level,
                $message,
                ['sequence' => $index]
            );
        }
    }

    private static function seedNotifications(int $userId): void
    {
        if (Notification::unreadCount($userId) > 0) {
            return;
        }

        Notification::push($userId, 'success', 'Publication completed',
            'A video post was published to three Pages and verified.', '/posts');
        Notification::push($userId, 'action_required', 'Facebook verification required',
            'Demo Brand 01 needs a security check before queued work can continue.', '/queue');
        Notification::push($userId, 'info', 'Demo workspace ready',
            'Everything in this workspace is simulated — nothing is sent to Facebook.', '/dashboard');
    }
}
