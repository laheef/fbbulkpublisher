<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Clock;
use App\Core\Config;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\BrowserWorker;
use App\Models\WorkerLog;

/**
 * Human-facing view of the worker fleet. (The machine-facing API lives in
 * WorkerController.)
 */
final class WorkerDashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $userId = $this->requireUserId();

        $workers = BrowserWorker::forUser($userId, 100);
        foreach ($workers as &$worker) {
            $worker['is_online'] = in_array((string) $worker['status'], ['ONLINE', 'BUSY', 'STARTING'], true);
            $worker['heartbeat_age'] = self::age((string) ($worker['last_heartbeat_at'] ?? ''));
            $worker['log_stats'] = WorkerLog::stats((int) $worker['id']);
        }
        unset($worker);

        return $this->view('workers/index', [
            'title'    => 'Workers',
            'workers'  => $workers,
            'summary'  => BrowserWorker::fleetSummary($userId),
            'offline_after' => Config::int('security.worker_offline_after_s', 120),
        ]);
    }

    public function show(Request $request, array $params): Response
    {
        $this->requireUserId();
        $workerId = $this->paramInt($params, 'id', 'worker');
        $worker = $this->owned(BrowserWorker::find($workerId), 'worker');

        $logs = WorkerLog::forWorker($workerId, 200);
        foreach ($logs as &$log) {
            $log['context_decoded'] = \App\Core\Support::jsonDecode((string) ($log['context'] ?? ''));
        }
        unset($log);

        $jobs = \App\Core\Database::instance()->select(
            'SELECT j.*, f.page_name FROM jobs j JOIN facebook_pages f ON f.id = j.page_id
             WHERE j.worker_id = ? ORDER BY j.updated_at DESC LIMIT 50',
            [$workerId]
        );

        return $this->view('workers/show', [
            'title'   => $worker['name'],
            'worker'  => $worker,
            'logs'    => $logs,
            'jobs'    => $jobs,
            'stats'   => WorkerLog::stats($workerId),
            'accounts' => \App\Models\FacebookAccount::forUser($this->requireUserId()),
            'capabilities' => \App\Core\Support::jsonDecode((string) ($worker['capabilities'] ?? '')),
        ]);
    }

    /** Enable/disable a machine (a disabled worker refuses to claim jobs). */
    public function toggle(Request $request, array $params): Response
    {
        $this->requireUserId();
        $workerId = $this->paramInt($params, 'id', 'worker');
        $worker = $this->owned(BrowserWorker::find($workerId), 'worker');

        $enable = !$request->bool('disable');
        BrowserWorker::modify($workerId, [
            'is_enabled' => $enable ? 1 : 0,
            'status'     => $enable
                ? ($worker['last_heartbeat_at'] !== null ? 'ONLINE' : 'OFFLINE')
                : 'PAUSED',
        ]);

        $this->log($enable ? 'worker.enabled' : 'worker.disabled', 'worker', $workerId);

        return $this->json(['worker' => BrowserWorker::find($workerId)]);
    }

    /** Pause/resume automation on one machine without touching the queue. */
    public function pause(Request $request, array $params): Response
    {
        return $this->setPaused($request, $params, true);
    }

    public function resume(Request $request, array $params): Response
    {
        return $this->setPaused($request, $params, false);
    }

    private function setPaused(Request $request, array $params, bool $paused): Response
    {
        $this->requireUserId();
        $workerId = $this->paramInt($params, 'id', 'worker');
        $this->owned(BrowserWorker::find($workerId), 'worker');

        BrowserWorker::modify($workerId, ['status' => $paused ? 'PAUSED' : 'ONLINE']);
        $this->log($paused ? 'worker.paused' : 'worker.resumed', 'worker', $workerId);

        return $this->json(['worker' => BrowserWorker::find($workerId)]);
    }

    /** Rotate the token for a machine that may have been compromised. */
    public function rotate(Request $request, array $params): Response
    {
        $this->requireUserId();
        $workerId = $this->paramInt($params, 'id', 'worker');
        $this->owned(BrowserWorker::find($workerId), 'worker');

        $token = BrowserWorker::rotateToken($workerId);
        $this->log('worker.token_rotated', 'worker', $workerId);

        return $this->json([
            'token' => $token,
            'note'  => 'Copy this token into the LinkEasy Publisher app on that PC. It will not be shown again.',
        ]);
    }

    /** Remove a machine and detach its accounts. */
    public function remove(Request $request, array $params): Response
    {
        $this->requireUserId();
        $workerId = $this->paramInt($params, 'id', 'worker');
        $this->owned(BrowserWorker::find($workerId), 'worker');

        $active = (int) \App\Core\Database::instance()->scalar(
            "SELECT COUNT(*) FROM jobs WHERE worker_id = ? AND status IN ('CLAIMED','PROCESSING','UPLOADING','PUBLISHING','VERIFYING')",
            [$workerId]
        );

        if ($active > 0 && !$request->bool('force')) {
            throw HttpException::conflict(
                "This worker is currently running {$active} job(s). Pause it and retry, or confirm to detach anyway.",
                ['active_jobs' => (string) $active]
            );
        }

        BrowserWorker::releaseExpiredLeases();
        \App\Core\Database::instance()->update('facebook_accounts', ['worker_id' => null, 'status' => 'DISCONNECTED'], ['worker_id' => $workerId]);
        BrowserWorker::remove($workerId);

        $this->log('worker.removed', 'worker', $workerId, ['detached_accounts' => 1]);

        return $this->redirect('/workers');
    }

    /** Per-worker settings (concurrency, tracing, retention ...). */
    public function settings(Request $request, array $params): Response
    {
        $userId = $this->requireUserId();
        $workerId = $this->paramInt($params, 'id', 'worker');
        $this->owned(BrowserWorker::find($workerId), 'worker');

        $validator = Validator::make($request->all())
            ->integer('max_concurrent_browsers', 1, 16)
            ->integer('max_concurrent_jobs', 1, 32)
            ->integer('max_jobs_per_worker', 1, 500)
            ->integer('idle_browser_timeout_s', 30, 7200)
            ->in('trace_mode', ['off', 'failures_only', 'all']);

        if ($validator->fails()) {
            throw HttpException::validation('Some settings were out of range.', $validator->errors());
        }

        $keys = ['max_concurrent_browsers', 'max_concurrent_jobs', 'max_jobs_per_worker',
                 'idle_browser_timeout_s', 'trace_mode'];

        foreach ($keys as $key) {
            if ($request->has($key)) {
                \App\Models\Setting::setValue($key, (string) $request->input($key), 'worker', $userId, $workerId);
            }
        }

        BrowserWorker::modify($workerId, [
            'max_concurrent_jobs'     => $request->has('max_concurrent_jobs') ? $request->int('max_concurrent_jobs') : null,
            'max_concurrent_browsers' => $request->has('max_concurrent_browsers') ? $request->int('max_concurrent_browsers') : null,
        ]);

        $this->log('worker.settings_updated', 'worker', $workerId);

        return $this->json(['config' => \App\Models\Setting::effectiveForWorker($userId, $workerId)]);
    }

    private static function age(string $utc): ?int
    {
        $ts = Clock::parseUtc($utc)?->getTimestamp();
        return $ts === null ? null : max(0, time() - $ts);
    }
}
