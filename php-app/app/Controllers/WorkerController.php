<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Support;
use App\Models\BrowserWorker;
use App\Models\FacebookAccount;
use App\Models\FacebookPage;
use App\Models\Job;
use App\Models\Media;
use App\Models\Notification;
use App\Models\Setting;
use App\Models\WorkerLog;
use App\Services\JobFailureCodes;
use App\Services\MediaProbe;
use App\Services\MediaService;
use App\Services\WorkerAuth;
use App\Core\Auth;

/**
 * The worker API — how the Windows automation engine talks to the control
 * plane. Every endpoint authenticates the worker token, then re-checks that the
 * referenced rows belong to the worker's own tenant.
 *
 * Nothing here executes automation: the server hands work out and records what
 * the machine reports back.
 */
final class WorkerController extends Controller
{
    /**
     * POST /worker/register
     * Bootstrap: the desktop app exchanges workspace credentials for a worker
     * token. The token is returned once and stored on the machine with DPAPI.
     */
    public function register(Request $request): Response
    {
        $payload = $request->json();
        $installationId = trim((string) ($payload['installation_id'] ?? ''));
        $workerId = trim((string) ($payload['worker_id'] ?? ''));

        if ($installationId === '' || strlen($installationId) < 8 || $workerId === '' || strlen($workerId) < 8) {
            throw HttpException::validation('installation_id and worker_id are required.');
        }

        $existingToken = $request->bearerToken();
        $user = null;

        if (is_string($existingToken) && $existingToken !== '') {
            // Re-registration / token rotation by a known machine.
            $worker = BrowserWorker::findByToken($existingToken);
            if ($worker === null) {
                throw HttpException::unauthorized('This machine is not recognised. Sign in to the workspace again.');
            }
            $user = \App\Models\User::find((int) $worker['user_id']);
        } else {
            $email = mb_strtolower(trim((string) ($payload['email'] ?? '')));
            $password = (string) ($payload['password'] ?? '');
            $user = \App\Models\User::findByEmail($email);

            if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
                \App\Models\ActivityLog::record(null, 'worker.register_failed', 'worker', null, $request->ip(), 'worker');
                throw HttpException::unauthorized('Those workspace credentials are not valid.');
            }
            if (($user['status'] ?? 'ACTIVE') !== 'ACTIVE') {
                throw HttpException::forbidden('That workspace account is suspended.');
            }
        }

        WorkerAuth::throttle('register:' . $request->ip(), 30);

        $result = BrowserWorker::register(
            (int) $user['id'],
            $installationId,
            $workerId,
            (string) ($payload['name'] ?? 'Windows PC'),
            (array) ($payload['meta'] ?? [])
        );

        Logger::info('Worker registered', [
            'worker_id' => $result['worker']['id'] ?? null,
            'user_id'   => (int) $user['id'],
            'os'        => $payload['meta']['os'] ?? 'unknown',
        ]);

        \App\Models\ActivityLog::record((int) $user['id'], 'worker.registered', 'worker',
            (int) ($result['worker']['id'] ?? 0), $request->ip(), 'worker');

        return $this->json([
            'worker'   => self::presentWorker($result['worker']),
            'token'    => $result['token'],
            'token_note' => 'Store this securely on the machine (DPAPI). It is shown only once.',
            'heartbeat_interval_s' => Config::int('security.heartbeat_interval_s', 20),
            'config'   => Setting::effectiveForWorker((int) $user['id'], (int) ($result['worker']['id'] ?? 0)),
        ], 201);
    }

    /** POST /worker/heartbeat */
    public function heartbeat(Request $request): Response
    {
        $worker = WorkerAuth::authenticate($request);
        WorkerAuth::throttle('hb:' . $worker['id'], 600);

        $payload = $request->json();
        BrowserWorker::heartbeat((int) $worker['id'], $payload);

        $settings = Setting::effectiveForWorker((int) $worker['user_id'], (int) $worker['id']);

        return $this->json([
            'server_time_utc' => Clock::nowString(),
            'next_heartbeat_s' => max(5, min(120, (int) ($payload['interval_s'] ?? Config::int('security.heartbeat_interval_s', 20)))),
            'pending_jobs'    => (int) Database::instance()->scalar(
                "SELECT COUNT(*) FROM jobs WHERE user_id = ? AND status IN ('QUEUED','RETRYING')",
                [(int) $worker['user_id']]
            ),
            'is_paused'       => (string) $worker['status'] === 'PAUSED' || !empty($payload['paused']),
            'config'          => $settings,
            'unread_notifications' => Notification::unreadCount((int) $worker['user_id']),
        ]);
    }

    /** GET /worker/config */
    public function config(Request $request): Response
    {
        $worker = WorkerAuth::authenticate($request);
        $userId = (int) $worker['user_id'];

        $accounts = Database::instance()->select(
            "SELECT id, label, profile_ref, status FROM facebook_accounts
             WHERE user_id = ? AND worker_id = ? AND status <> 'DISABLED' ORDER BY id ASC",
            [$userId, (int) $worker['id']]
        );

        return $this->json([
            'config'   => Setting::effectiveForWorker($userId, (int) $worker['id']),
            'accounts' => $accounts,
            'provider' => \App\Services\Publishing\ProviderFactory::currentKey(),
            'limits'   => [
                'max_concurrent_browsers' => (int) Setting::getValue('max_concurrent_browsers', '2', 'user', $userId, (int) $worker['id']),
                'max_concurrent_jobs'     => (int) Setting::getValue('max_concurrent_jobs', '2', 'user', $userId, (int) $worker['id']),
                'max_jobs_per_worker'     => (int) Setting::getValue('max_jobs_per_worker', '10', 'user', $userId, (int) $worker['id']),
                'idle_browser_timeout_s'  => (int) Setting::getValue('idle_browser_timeout_s', '300', 'user', $userId, (int) $worker['id']),
                'lease_seconds'           => Config::int('queue.lease_seconds', 900),
            ],
        ]);
    }

    /** GET /worker/jobs  — atomically claim up to N due jobs. */
    public function claimJobs(Request $request): Response
    {
        $worker = WorkerAuth::authenticate($request);
        WorkerAuth::throttle('claim:' . $worker['id'], 300);

        if ((string) $worker['status'] === 'PAUSED') {
            return $this->json(['jobs' => [], 'paused' => true, 'message' => 'The worker is paused.']);
        }

        $limit = max(1, min(Config::int('queue.claim_batch_size', 5), $request->query('limit') !== null ? (int) $request->query('limit') : 5));
        $claimed = Job::claimForWorker($worker, $limit);

        $jobs = [];
        foreach ($claimed as $job) {
            $detail = Job::forWorker((int) $job['id'], (int) $worker['id']);
            if ($detail !== null) {
                $jobs[] = self::presentJob($detail);
            }
        }

        return $this->json([
            'jobs'  => $jobs,
            'count' => count($jobs),
            'lease_seconds' => Config::int('queue.lease_seconds', 900),
        ]);
    }

    /** POST /worker/jobs/{id}/progress */
    public function jobProgress(Request $request, array $params): Response
    {
        $worker = WorkerAuth::authenticate($request);
        $jobId = $this->paramInt($params, 'id', 'job');
        $job = $this->ownedByWorker($jobId, (int) $worker['id'], (int) $worker['user_id']);

        $payload = $request->json();
        $ok = Job::progress($jobId, [
            'status'      => (string) ($payload['status'] ?? 'PROCESSING'),
            'stage'       => $payload['stage'] ?? null,
            'progress_pct' => $payload['progress_pct'] ?? null,
            'message'     => $payload['message'] ?? null,
            'actor'       => 'worker',
        ]);

        return $this->json(['accepted' => $ok, 'job_id' => $jobId, 'status' => Job::find($jobId)['status'] ?? null]);
    }

    /** POST /worker/jobs/{id}/complete */
    public function jobComplete(Request $request, array $params): Response
    {
        $worker = WorkerAuth::authenticate($request);
        $jobId = $this->paramInt($params, 'id', 'job');
        $this->ownedByWorker($jobId, (int) $worker['id'], (int) $worker['user_id']);

        $payload = $request->json();
        $resultUrl = trim((string) ($payload['result_url'] ?? ''));
        $verified = !empty($payload['verified']);

        // A worker may not report success without a verification signal: either a
        // located post URL, or an explicit verification result (§33).
        if ($resultUrl === '' && !$verified) {
            throw HttpException::validation('A completed job must include result_url or verified=true.');
        }

        Job::complete($jobId, [
            'result_url'      => $resultUrl,
            'screenshot_path' => $payload['screenshot_path'] ?? null,
            'trace_path'      => $payload['trace_path'] ?? null,
            'meta'            => (array) ($payload['meta'] ?? []) + ['verified' => $verified],
        ]);

        Notification::push(
            (int) $worker['user_id'],
            'success',
            'Publication verified',
            'A post was published and confirmed on Facebook.',
            '/queue?job=' . $jobId,
            $jobId
        );

        Logger::info('Job completed', ['job_id' => $jobId, 'worker_id' => $worker['id'], 'verified' => $verified]);

        return $this->json(['job' => Job::find($jobId)], 200);
    }

    /** POST /worker/jobs/{id}/fail */
    public function jobFail(Request $request, array $params): Response
    {
        $worker = WorkerAuth::authenticate($request);
        $jobId = $this->paramInt($params, 'id', 'job');
        $this->ownedByWorker($jobId, (int) $worker['id'], (int) $worker['user_id']);

        $payload = $request->json();
        $code = strtoupper((string) ($payload['error_code'] ?? 'UNKNOWN_TRANSIENT'));

        if (!in_array($code, JobFailureCodes::all(), true)) {
            $code = 'UNKNOWN_TRANSIENT';
        }

        $outcome = Job::fail($jobId, [
            'error_code'           => $code,
            'error_message'        => (string) ($payload['error_message'] ?? 'The worker reported a failure.'),
            'retryable'            => JobFailureCodes::isRetryable($code),
            'user_action_required' => JobFailureCodes::requiresUserAction($code),
            'screenshot_path'      => $payload['screenshot_path'] ?? null,
            'trace_path'           => $payload['trace_path'] ?? null,
        ]);

        Logger::warn('Job failed', ['job_id' => $jobId, 'code' => $code, 'outcome' => $outcome['status']]);

        return $this->json(['job' => Job::find($jobId), 'outcome' => $outcome]);
    }

    /**
     * POST /worker/challenge
     * Facebook presented a CAPTCHA / checkpoint / 2FA. The worker stops and asks
     * the operator to complete it by hand. Nothing is solved automatically.
     */
    public function challenge(Request $request): Response
    {
        $worker = WorkerAuth::authenticate($request);
        $payload = $request->json();

        $jobId = (int) ($payload['job_id'] ?? 0);
        $code = strtoupper((string) ($payload['challenge_type'] ?? 'SECURITY_CHALLENGE'));
        if (!in_array($code, JobFailureCodes::USER_ACTION, true)) {
            $code = 'SECURITY_CHALLENGE';
        }

        $accountId = (int) ($payload['account_id'] ?? 0);
        $message = mb_substr((string) ($payload['message'] ?? 'Facebook requires a security verification.'), 0, 1000);

        if ($accountId > 0) {
            $account = FacebookAccount::find($accountId);
            if ($account !== null && (int) $account['user_id'] === (int) $worker['user_id']) {
                FacebookAccount::setStatus($accountId, 'CHALLENGE_REQUIRED', $code, $message);
                Database::instance()->update('facebook_pages', ['status' => 'CHALLENGE_REQUIRED'], ['account_id' => $accountId]);
            }
        }

        if ($jobId > 0) {
            $job = Job::find($jobId);
            if ($job !== null && (int) $job['user_id'] === (int) $worker['user_id']) {
                Job::fail($jobId, [
                    'error_code'           => $code,
                    'error_message'        => $message,
                    'user_action_required' => true,
                    'screenshot_path'      => $payload['screenshot_path'] ?? null,
                    'trace_path'           => $payload['trace_path'] ?? null,
                ]);
            }
        }

        Notification::push(
            (int) $worker['user_id'],
            'action_required',
            'Facebook verification required',
            $message,
            '/queue',
            $jobId > 0 ? $jobId : null
        );

        Logger::warn('Security challenge reported', [
            'worker_id' => $worker['id'],
            'job_id'    => $jobId,
            'type'      => $code,
        ]);

        return $this->json([
            'acknowledged' => true,
            'job_id'       => $jobId,
            'instruction'  => 'Automation is paused. Bring the browser to the foreground and let the operator finish the check.',
        ]);
    }

    /** GET /worker/jobs/{id}/media — streams the media the worker must upload. */
    public function jobMedia(Request $request, array $params): Response
    {
        $worker = WorkerAuth::authenticate($request);
        $jobId = $this->paramInt($params, 'id', 'job');
        $job = $this->ownedByWorker($jobId, (int) $worker['id'], (int) $worker['user_id']);

        if (empty($job['media_id'])) {
            throw HttpException::notFound('This job has no media attached.');
        }

        $media = $this->ownedByWorkerMedia((int) $job['media_id'], (int) $worker['user_id']);
        MediaService::stream($media);
        exit;
    }

    /** POST /worker/media/{id}/probe — FFmpeg metadata reported from Windows. */
    public function mediaProbe(Request $request, array $params): Response
    {
        $worker = WorkerAuth::authenticate($request);
        $mediaId = $this->paramInt($params, 'id', 'media');
        $this->ownedByWorkerMedia($mediaId, (int) $worker['user_id']);

        $payload = $request->json();

        Media::markProbed($mediaId, [
            'width'        => isset($payload['width']) ? (int) $payload['width'] : null,
            'height'       => isset($payload['height']) ? (int) $payload['height'] : null,
            'duration_s'   => isset($payload['duration_s']) ? (float) $payload['duration_s'] : null,
            'fps'          => isset($payload['fps']) ? (float) $payload['fps'] : null,
            'codec'        => isset($payload['codec']) ? (string) $payload['codec'] : null,
            'aspect_ratio' => isset($payload['aspect_ratio']) ? (string) $payload['aspect_ratio'] : null,
            'thumbnail_path' => isset($payload['thumbnail_path']) ? (string) $payload['thumbnail_path'] : null,
        ]);

        return $this->json(['media_id' => $mediaId, 'probe_status' => 'PROBED']);
    }

    /**
     * POST /worker/media/{id}/thumbnail — upload a generated poster frame.
     * Stored as an opaque relative reference; never served outside the tenant.
     */
    public function mediaThumbnail(Request $request, array $params): Response
    {
        $worker = WorkerAuth::authenticate($request);
        $mediaId = $this->paramInt($params, 'id', 'media');
        $media = $this->ownedByWorkerMedia($mediaId, (int) $worker['user_id']);

        $file = $_FILES['thumbnail'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
            throw HttpException::validation('A thumbnail file is required.');
        }

        $mime = MediaService::sniffMime((string) $file['tmp_name']);
        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
            throw HttpException::validation('Thumbnails must be JPEG or PNG.');
        }

        $dir = rtrim(Config::str('uploads.disk_path'), '/') . '/thumbs';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $name = 'media_' . $mediaId . '_' . bin2hex(random_bytes(6)) . ($mime === 'image/png' ? '.png' : '.jpg');
        if (!move_uploaded_file((string) $file['tmp_name'], $dir . '/' . $name)) {
            if (!@rename((string) $file['tmp_name'], $dir . '/' . $name)) {
                throw new \RuntimeException('Unable to store the thumbnail.');
            }
        }
        @chmod($dir . '/' . $name, 0640);

        Media::modify($mediaId, ['thumbnail_path' => 'thumbs/' . $name]);

        return $this->json(['media_id' => $mediaId, 'thumbnail_path' => 'thumbs/' . $name]);
    }

    /** POST /worker/jobs/{id}/screenshot */
    public function jobScreenshot(Request $request, array $params): Response
    {
        $worker = WorkerAuth::authenticate($request);
        $jobId = $this->paramInt($params, 'id', 'job');
        $this->ownedByWorker($jobId, (int) $worker['id'], (int) $worker['user_id']);

        $file = $_FILES['screenshot'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
            throw HttpException::validation('A screenshot file is required.');
        }

        $mime = MediaService::sniffMime((string) $file['tmp_name']);
        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
            throw HttpException::validation('Screenshots must be JPEG or PNG.');
        }

        $folder = Clock::now()->format('Y');
        $dir = rtrim(Config::str('uploads.disk_path'), '/') . '/screenshots/' . $folder;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $relative = $folder . '/job_' . $jobId . '_' . bin2hex(random_bytes(5)) . ($mime === 'image/png' ? '.png' : '.jpg');
        $absolute = $dir . '/' . basename($relative);

        if (!move_uploaded_file((string) $file['tmp_name'], $absolute)) {
            if (!@rename((string) $file['tmp_name'], $absolute)) {
                throw new \RuntimeException('Unable to store the screenshot.');
            }
        }
        @chmod($absolute, 0640);

        Database::instance()->update('jobs', ['screenshot_path' => $relative], ['id' => $jobId]);

        return $this->json(['job_id' => $jobId, 'screenshot_path' => $relative]);
    }

    /** POST /worker/logs */
    public function logs(Request $request): Response
    {
        $worker = WorkerAuth::authenticate($request);
        $payload = $request->json();
        $lines = (array) ($payload['lines'] ?? []);

        $ingested = 0;
        foreach (array_slice($lines, 0, 200) as $line) {
            if (!is_array($line)) {
                continue;
            }
            WorkerLog::ingest(
                (int) $worker['id'],
                (int) $worker['user_id'],
                (string) ($line['channel'] ?? 'worker'),
                (string) ($line['level'] ?? 'info'),
                (string) ($line['message'] ?? ''),
                (array) ($line['context'] ?? [])
            );
            $ingested++;
        }

        return $this->json(['ingested' => $ingested]);
    }

    /** POST /worker/accounts — create a browser profile for a Facebook login. */
    public function createAccount(Request $request): Response
    {
        $worker = WorkerAuth::authenticate($request);
        $payload = $request->json();

        $existing = Database::instance()->first(
            'SELECT * FROM facebook_accounts WHERE worker_id = ? AND status IN (\'CONNECTED\',\'DISCONNECTED\',\'AUTH_REQUIRED\',\'CHALLENGE_REQUIRED\') ORDER BY id ASC LIMIT 1',
            [(int) $worker['id']]
        );

        if ($existing !== null && empty($payload['force_new'])) {
            return $this->json([
                'account'  => self::presentAccount($existing),
                'reused'   => true,
                'message'  => 'This machine already owns a profile for that workspace.',
                'profile_ref' => $existing['profile_ref'],
            ]);
        }

        $account = FacebookAccount::create([
            'user_id'     => (int) $worker['user_id'],
            'label'       => mb_substr((string) ($payload['label'] ?? ('Facebook account on ' . $worker['name'])), 0, 120),
            'profile_ref' => FacebookAccount::newProfileRef(),
            'worker_id'   => (int) $worker['id'],
            'status'      => 'DISCONNECTED',
        ]);

        if ($account === null) {
            throw new HttpException(500, 'The account could not be created.');
        }

        return $this->json([
            'account'     => self::presentAccount($account),
            'profile_ref' => $account['profile_ref'],
            'reused'      => false,
            'instruction' => 'Launch Chromium with this profile_ref, then open facebook.com and let the operator sign in.',
        ], 201);
    }

    /** POST /worker/accounts/{id}/pages — publish discovered Pages. */
    public function syncPages(Request $request, array $params): Response
    {
        $worker = WorkerAuth::authenticate($request);
        $accountId = $this->paramInt($params, 'id', 'account');
        $account = $this->ownedByWorkerAccount($accountId, (int) $worker['id']);

        $payload = $request->json();
        $pages = (array) ($payload['pages'] ?? []);

        if ($pages === []) {
            throw HttpException::validation('No Pages were supplied.');
        }

        if (count($pages) > 2000) {
            $pages = array_slice($pages, 0, 2000);
        }

        $result = FacebookPage::syncFromWorker((int) $account['user_id'], $accountId, $pages);

        FacebookAccount::modify($accountId, [
            'status'           => 'CONNECTED',
            'fb_account_name'  => isset($payload['account_name']) ? mb_substr((string) $payload['account_name'], 0, 190) : $account['fb_account_name'],
            'fb_account_id'    => isset($payload['account_id']) ? mb_substr((string) $payload['account_id'], 0, 64) : $account['fb_account_id'],
            'last_verified_at' => Clock::nowString(),
            'last_error_code'  => null,
            'last_error'       => null,
        ]);

        Logger::info('Pages synchronised', [
            'account_id' => $accountId,
            'added'      => $result['added'],
            'updated'    => $result['updated'],
        ]);

        return $this->json([
            'account_id' => $accountId,
            'added'      => $result['added'],
            'updated'    => $result['updated'],
            'page_ids'   => $result['ids'],
        ]);
    }

    /** POST /worker/accounts/{id}/status — session state reported by the worker. */
    public function accountStatus(Request $request, array $params): Response
    {
        $worker = WorkerAuth::authenticate($request);
        $accountId = $this->paramInt($params, 'id', 'account');
        $account = $this->ownedByWorkerAccount($accountId, (int) $worker['id']);

        $payload = $request->json();
        $status = strtoupper((string) ($payload['status'] ?? 'DISCONNECTED'));
        if (!in_array($status, FacebookAccount::STATUSES, true)) {
            throw HttpException::validation('Unsupported account status.');
        }

        FacebookAccount::setStatus(
            $accountId,
            $status,
            isset($payload['error_code']) ? (string) $payload['error_code'] : null,
            isset($payload['message']) ? (string) $payload['message'] : null
        );

        if ($status === 'CONNECTED') {
            Database::instance()->update('facebook_pages',
                ['status' => 'ENABLED', 'last_verified_at' => Clock::nowString()],
                ['account_id' => $accountId, 'status' => 'AUTH_REQUIRED']
            );
        }

        return $this->json(['account' => self::presentAccount(FacebookAccount::find($accountId) ?? $account)]);
    }

    /**
     * POST /worker/jobs/{id}/verify
     * A publish attempt whose outcome is uncertain. The worker reports what it
     * found rather than guessing: either the post exists (complete it) or it
     * cannot be confirmed (PUBLISH_VERIFICATION_REQUIRED), which prevents a
     * blind re-publish (§32, §33).
     */
    public function verifyJob(Request $request, array $params): Response
    {
        $worker = WorkerAuth::authenticate($request);
        $jobId = $this->paramInt($params, 'id', 'job');
        $this->ownedByWorker($jobId, (int) $worker['id'], (int) $worker['user_id']);

        $payload = $request->json();
        $found = !empty($payload['published']);
        $url = trim((string) ($payload['result_url'] ?? ''));

        if ($found && $url !== '') {
            Job::complete($jobId, [
                'result_url' => $url,
                'meta'       => ['verification' => 'located_after_uncertainty'] + (array) ($payload['meta'] ?? []),
            ]);
            return $this->json(['action' => 'completed', 'job' => Job::find($jobId)]);
        }

        $outcome = Job::fail($jobId, [
            'error_code'           => 'PUBLISH_VERIFICATION_REQUIRED',
            'error_message'        => 'The publication could not be confirmed. Check this Page manually before retrying to avoid a duplicate post.',
            'retryable'            => false,
            'user_action_required' => true,
            'screenshot_path'      => $payload['screenshot_path'] ?? null,
        ]);

        return $this->json(['action' => 'verification_required', 'outcome' => $outcome, 'job' => Job::find($jobId)]);
    }

    // ---------------------------------------------------------------------
    // helpers
    // ---------------------------------------------------------------------

    /**
     * A worker may report on a job it currently holds. It may also still report
     * on a job that was released back to the pool mid-flight (the server clears
     * worker_id when a challenge or lease expiry happens), because its own
     * in-flight report can legitimately arrive after that release. Anything
     * already terminal, or owned by another machine, is refused.
     */
    private function ownedByWorker(int $jobId, int $workerId, int $workerUserId = 0): array
    {
        $job = Job::find($jobId);
        if ($job === null) {
            throw HttpException::notFound('That job does not exist.');
        }

        if ($workerUserId > 0 && (int) $job['user_id'] !== $workerUserId) {
            throw HttpException::forbidden('That job belongs to a different workspace.');
        }

        if ((int) $job['worker_id'] === $workerId) {
            return $job;
        }

        $released = $job['worker_id'] === null
            && in_array((string) $job['status'], array_merge(Job::ACTIVE, Job::USER_ACTION, ['RETRYING', 'QUEUED']), true);

        if ($released) {
            return $job;
        }

        throw HttpException::forbidden('This job is not assigned to your worker.');
    }

    private function ownedByWorkerMedia(int $mediaId, int $workerUserId): array
    {
        $media = Media::find($mediaId);
        if ($media === null || (int) $media['user_id'] !== $workerUserId) {
            throw HttpException::notFound('That media file does not exist.');
        }
        return $media;
    }

    private function ownedByWorkerAccount(int $accountId, int $workerId): array
    {
        $account = FacebookAccount::find($accountId);
        if ($account === null) {
            throw HttpException::notFound('That account does not exist.');
        }
        // Either the machine already owns it, or it is unassigned and can adopt it.
        if ($account['worker_id'] !== null && (int) $account['worker_id'] !== $workerId) {
            throw HttpException::forbidden('This account belongs to a different worker.');
        }
        if ($account['worker_id'] === null) {
            FacebookAccount::modify($accountId, ['worker_id' => $workerId]);
            $account['worker_id'] = $workerId;
        }
        return $account;
    }

    /** @return array<string,mixed> */
    private static function presentWorker(array $worker): array
    {
        unset($worker['token_hash']);
        return $worker;
    }

    /** @return array<string,mixed> */
    private static function presentAccount(array $account): array
    {
        // Never expose the internal storage reference outside the worker that owns it.
        return [
            'id'            => (int) $account['id'],
            'label'         => $account['label'],
            'profile_ref'   => $account['profile_ref'],
            'status'        => $account['status'],
            'fb_account_name' => $account['fb_account_name'],
            'page_count'    => (int) $account['page_count'],
            'last_verified_at' => Clock::iso($account['last_verified_at']),
        ];
    }

    /** @return array<string,mixed> */
    private static function presentJob(array $job): array
    {
        return [
            'id'              => (int) $job['id'],
            'uuid'            => $job['uuid'],
            'idempotency_key' => $job['idempotency_key'],
            'job_type'        => $job['job_type'],
            'status'          => $job['status'],
            'attempts'        => (int) $job['attempts'],
            'max_attempts'    => (int) $job['max_attempts'],
            'scheduled_at_utc' => Clock::iso($job['scheduled_at']),
            'lease_seconds'   => Config::int('queue.lease_seconds', 900),
            'account'         => [
                'id'          => (int) $job['account_id'],
                'label'       => $job['account_label'] ?? null,
                'profile_ref' => $job['profile_ref'] ?? null,
            ],
            'page'            => [
                'id'       => (int) $job['page_id'],
                'fb_page_id' => $job['fb_page_id'] ?? null,
                'name'     => $job['page_name'] ?? null,
                'url'      => $job['page_url'] ?? null,
            ],
            'content'         => [
                'caption'  => $job['caption'] ?? '',
                'hashtags' => $job['hashtags'] ?? null,
                'link_url' => $job['link_url'] ?? null,
                'title'    => $job['title'] ?? null,
                'kind'     => $job['post_kind'] ?? 'TEXT',
            ],
            'media'           => empty($job['media_id']) ? null : [
                'id'         => (int) $job['media_id'],
                'kind'       => $job['media_kind'] ?? null,
                'name'       => $job['original_name'] ?? null,
                'mime_type'  => $job['mime_type'] ?? null,
                'size_bytes' => isset($job['size_bytes']) ? (int) $job['size_bytes'] : null,
                'strategy'   => $job['strategy'] ?? 'SERVER',
                'download_url' => $job['media_url'] ?? null,
            ],
            'execution_plan'  => Support::jsonDecode((string) ($job['post_settings'] ?? '')) ?: Support::jsonDecode((string) ($job['payload'] ?? ''))['execution_plan'] ?? null,
            'payload'         => Support::jsonDecode((string) ($job['payload'] ?? '')),
        ];
    }
}
