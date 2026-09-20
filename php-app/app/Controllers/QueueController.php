<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Clock;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\BrowserWorker;
use App\Models\Job;
use App\Models\JobEvent;
use App\Core\Auth;

final class QueueController extends Controller
{
    public function index(Request $request): Response
    {
        $userId = $this->requireUserId();

        $filters = [
            'status'    => $request->string('status'),
            'page_id'   => $request->int('page'),
            'worker_id' => $request->int('worker'),
        ];

        $jobs = Job::forUser($userId, $filters, 100);
        foreach ($jobs as &$job) {
            $job['local_time'] = Clock::utcToLocal((string) $job['scheduled_at'], Auth::timezone());
            $job['has_screenshot'] = !empty($job['screenshot_path']);
        }
        unset($job);

        return $this->view('queue/index', [
            'title'    => 'Queue',
            'jobs'     => $jobs,
            'filters'  => $filters,
            'summary'  => Job::summaryForUser($userId),
            'workers'  => BrowserWorker::forUser($userId),
            'statuses' => Job::STATES,
            'viewing'  => $request->int('job') > 0 ? self::jobDetail($userId, $request->int('job')) : null,
        ]);
    }

    /** @return array<string,mixed>|null */
    private static function jobDetail(int $userId, int $jobId): ?array
    {
        $job = Database::instance()->first(
            "SELECT j.*, f.page_name, f.page_url, a.label AS account_label, w.name AS worker_name,
                    p.caption, p.kind AS post_kind, p.id AS post_id
             FROM jobs j
             JOIN facebook_pages f ON f.id = j.page_id
             JOIN facebook_accounts a ON a.id = j.account_id
             LEFT JOIN browser_workers w ON w.id = j.worker_id
             JOIN posts p ON p.id = j.post_id
             WHERE j.id = ? AND j.user_id = ? LIMIT 1",
            [$jobId, $userId]
        );

        if ($job === null) {
            return null;
        }

        $job['events'] = JobEvent::timeline($jobId, 200);
        return $job;
    }

    public function retry(Request $request, array $params): Response
    {
        $this->requireUserId();
        $jobId = $this->paramInt($params, 'id', 'job');
        $job = $this->owned(Job::find($jobId), 'job');

        if (!Job::requeue($jobId, $request->string('scheduled_at') ?: null)) {
            throw HttpException::conflict('This job is not in a state that can be retried.');
        }

        $this->log('job.retried', 'job', $jobId, ['from_status' => $job['status']]);

        return $this->json(['job' => Job::find($jobId)]);
    }

    public function cancel(Request $request, array $params): Response
    {
        $this->requireUserId();
        $jobId = $this->paramInt($params, 'id', 'job');
        $this->owned(Job::find($jobId), 'job');

        if (!Job::cancel($jobId)) {
            throw HttpException::conflict('This job can no longer be cancelled.');
        }

        $this->log('job.cancelled', 'job', $jobId);

        return $this->json(['job' => Job::find($jobId)]);
    }

    public function pause(Request $request, array $params): Response
    {
        $this->requireUserId();
        $jobId = $this->paramInt($params, 'id', 'job');
        $this->owned(Job::find($jobId), 'job');

        if (!Job::pause($jobId)) {
            throw HttpException::conflict('Only queued jobs can be paused.');
        }

        return $this->json(['job' => Job::find($jobId)]);
    }

    public function resume(Request $request, array $params): Response
    {
        $this->requireUserId();
        $jobId = $this->paramInt($params, 'id', 'job');
        $this->owned(Job::find($jobId), 'job');

        if (!Job::resume($jobId)) {
            throw HttpException::conflict('This job is not paused.');
        }

        return $this->json(['job' => Job::find($jobId)]);
    }

    /**
     * Operator confirms they have finished a Facebook security check in the
     * browser. The job is requeued; the worker re-verifies the session before
     * doing anything else (§26).
     */
    public function resolveAction(Request $request, array $params): Response
    {
        $this->requireUserId();
        $jobId = $this->paramInt($params, 'id', 'job');
        $job = $this->owned(Job::find($jobId), 'job');

        if (!in_array((string) $job['status'], Job::USER_ACTION, true)) {
            throw HttpException::conflict('This job is not waiting on user action.');
        }

        if (in_array((string) $job['error_code'], ['CAPTCHA_DETECTED', 'SECURITY_CHALLENGE', 'CHECKPOINT', '2FA_REQUIRED', 'IDENTITY_VERIFICATION'], true)) {
            \App\Models\FacebookAccount::setStatus((int) $job['account_id'], 'CONNECTED');
        }

        Job::requeue($jobId);
        $this->log('job.action_resolved', 'job', $jobId, ['code' => $job['error_code']]);

        return $this->json([
            'job'     => Job::find($jobId),
            'message' => 'Thanks. The worker will re-verify the Facebook session and continue.',
        ]);
    }

    public function bulk(Request $request): Response
    {
        $userId = $this->requireUserId();
        $action = $request->string('action');
        $ids = array_map('intval', $request->arrayOfStrings('job_ids'));

        if ($ids === [] || !in_array($action, ['retry', 'cancel', 'pause', 'resume'], true)) {
            throw HttpException::badRequest('Select jobs and a supported action.');
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $owned = Database::instance()->select(
            "SELECT id FROM jobs WHERE user_id = ? AND id IN ({$placeholders})",
            array_merge([$userId], $ids)
        );

        $affected = 0;
        foreach ($owned as $row) {
            $jobId = (int) $row['id'];
            $affected += match ($action) {
                'retry'  => Job::requeue($jobId) ? 1 : 0,
                'cancel' => Job::cancel($jobId) ? 1 : 0,
                'pause'  => Job::pause($jobId) ? 1 : 0,
                'resume' => Job::resume($jobId) ? 1 : 0,
                default  => 0,
            };
        }

        $this->log('job.bulk_' . $action, 'job', null, ['affected' => $affected]);

        return $this->json(['affected' => $affected, 'action' => $action]);
    }

    /** Serve a failure screenshot to its owner only. */
    public function screenshot(Request $request, array $params): Response
    {
        $this->requireUserId();
        $jobId = $this->paramInt($params, 'id', 'job');
        $job = $this->owned(Job::find($jobId), 'job');

        $relative = (string) ($job['screenshot_path'] ?? '');
        if ($relative === '') {
            throw HttpException::notFound('No screenshot was captured for this job.');
        }

        // Screenshots arrive from the worker as opaque relative references.
        \App\Core\Support::assertSafeRelativePath($relative);
        $path = rtrim(\App\Core\Config::str('uploads.disk_path'), '/') . '/screenshots/' . $relative;

        if (!is_file($path)) {
            throw HttpException::notFound('That screenshot is no longer stored on the server.');
        }

        return Response::html((string) file_get_contents($path))
            ->withHeader('Content-Type', 'image/png')
            ->withHeader('Cache-Control', 'private, max-age=600');
    }
}
