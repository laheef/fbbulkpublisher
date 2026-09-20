<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\ActivityLog;
use App\Models\BrowserWorker;
use App\Models\User;
use App\Models\WorkerLog;
use App\Services\AnalyticsService;
use App\Services\DiagnosticsService;

/**
 * Administrator console (§64). Reached only by users whose role is 'admin';
 * the route carries the admin middleware as well as these re-checks.
 */
final class AdminController extends Controller
{
    public function index(Request $request): Response
    {
        $this->assertAdmin();

        return $this->view('admin/index', [
            'title'    => 'Administration',
            'overview' => AnalyticsService::platformOverview(30),
            'workers'  => BrowserWorker::forUser(0, 0) === [] ? self::allWorkers(50) : self::allWorkers(50),
            'queue'    => self::queueHealth(),
            'config'   => [
                'env'         => Config::str('app.env'),
                'debug'       => Config::bool('app.debug'),
                'provider'    => \App\Services\Publishing\ProviderFactory::currentKey(),
                'db_driver'   => Database::instance()->driver(),
                'php'         => PHP_VERSION,
                'ffmpeg'      => \App\Services\MediaProbe::version('ffmpeg'),
                'uploads_dir' => Config::str('uploads.disk_path'),
            ],
            'storage'  => self::storageBreakdown(),
        ]);
    }

    public function users(Request $request): Response
    {
        $this->assertAdmin();

        // Row counts per user, computed with a single grouped query each.
        $users = Database::instance()->select(
            'SELECT u.id, u.name, u.email, u.role, u.status, u.timezone, u.created_at, u.last_login_at,
                    (SELECT COUNT(*) FROM facebook_accounts a WHERE a.user_id = u.id) AS accounts,
                    (SELECT COUNT(*) FROM facebook_pages p WHERE p.user_id = u.id) AS pages,
                    (SELECT COUNT(*) FROM posts po WHERE po.user_id = u.id) AS posts,
                    (SELECT COUNT(*) FROM jobs j WHERE j.user_id = u.id) AS jobs,
                    (SELECT COUNT(*) FROM browser_workers w WHERE w.user_id = u.id) AS workers
             FROM users u ORDER BY u.created_at DESC LIMIT 200',
            []
        );

        return $this->view('admin/users', [
            'title' => 'Users',
            'users' => $users,
        ]);
    }

    public function updateUser(Request $request, array $params): Response
    {
        $this->assertAdmin();
        $userId = $this->paramInt($params, 'id', 'user');
        $target = User::find($userId);
        if ($target === null) {
            throw HttpException::notFound('That user does not exist.');
        }

        $action = $request->string('action');

        switch ($action) {
            case 'suspend':
                if ($userId === Auth::id()) {
                    throw HttpException::conflict('You cannot suspend your own account.');
                }
                User::modify($userId, ['status' => 'SUSPENDED']);
                break;

            case 'activate':
                User::modify($userId, ['status' => 'ACTIVE']);
                break;

            case 'promote':
                User::modify($userId, ['role' => 'admin']);
                break;

            case 'demote':
                if ($userId === Auth::id()) {
                    throw HttpException::conflict('You cannot remove your own administrator role.');
                }
                $admins = (int) Database::instance()->scalar("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'ACTIVE'", []);
                if ($admins <= 1) {
                    throw HttpException::conflict('At least one administrator must remain.');
                }
                User::modify($userId, ['role' => 'user']);
                break;

            default:
                throw HttpException::badRequest('Unsupported action.');
        }

        ActivityLog::record(Auth::id(), 'admin.user_' . $action, 'user', $userId, $request->ip(), 'user', Auth::id());

        return $this->json(['user' => User::safe($userId)]);
    }

    public function workers(Request $request): Response
    {
        $this->assertAdmin();

        $workers = BrowserWorker::forUser(0, 0) === [] ? [] : self::allWorkers(200);

        return $this->view('admin/workers', [
            'title'   => 'Fleet',
            'workers' => $workers,
            'summary' => BrowserWorker::fleetSummary(),
        ]);
    }

    public function activity(Request $request): Response
    {
        $this->assertAdmin();

        $filters = ['action' => $request->string('action')];
        $rows = ActivityLog::recent(200);
        if ($filters['action'] !== '') {
            $rows = array_values(array_filter($rows, static fn (array $r): bool =>
                stripos((string) $r['action'], $filters['action']) !== false));
        }

        return $this->view('admin/activity', [
            'title'   => 'Activity log',
            'entries' => $rows,
            'filters' => $filters,
        ]);
    }

    public function errors(Request $request): Response
    {
        $this->assertAdmin();

        $workerErrors = Database::instance()->select(
            "SELECT wl.*, w.name AS worker_name, u.email AS user_email
             FROM worker_logs wl
             LEFT JOIN browser_workers w ON w.id = wl.worker_id
             LEFT JOIN users u ON u.id = wl.user_id
             WHERE wl.level IN ('error','warn') ORDER BY wl.created_at DESC LIMIT 200",
            []
        );

        $failedJobs = Database::instance()->select(
            "SELECT j.*, f.page_name, u.email AS user_email
             FROM jobs j
             JOIN facebook_pages f ON f.id = j.page_id
             JOIN users u ON u.id = j.user_id
             WHERE j.status IN ('FAILED','USER_ACTION_REQUIRED','ACCOUNT_REAUTH_REQUIRED')
             ORDER BY j.updated_at DESC LIMIT 100",
            []
        );

        return $this->view('admin/errors', [
            'title'       => 'Errors',
            'workerErrors' => $workerErrors,
            'failedJobs'  => $failedJobs,
            'categories'  => \App\Services\AnalyticsService::failureCategories(0, 30) ?: [],
        ]);
    }

    /** Download a redacted diagnostics bundle (§73). */
    public function diagnostics(Request $request): Response
    {
        $this->assertAdmin();

        $export = DiagnosticsService::export(Auth::id());
        ActivityLog::record(Auth::id(), 'admin.diagnostics_export', 'system', null, $request->ip(), 'user', Auth::id());

        if ($request->bool('download')) {
            $contents = (string) file_get_contents($export['path']);
            return Response::html($contents)
                ->withHeader('Content-Type', str_ends_with($export['filename'], '.json') ? 'application/json' : 'application/zip')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $export['filename'] . '"')
                ->withHeader('Content-Length', (string) strlen($contents));
        }

        return $this->json([
            'filename' => $export['filename'],
            'bytes'    => $export['bytes'],
            'entries'  => $export['entries'],
            'download_url' => '/admin/diagnostics?download=1',
            'note'     => 'Passwords, cookies, session data and worker tokens are removed from this bundle.',
        ]);
    }

    /** Run housekeeping on demand (also runs from cron). */
    public function maintenance(Request $request): Response
    {
        $this->assertAdmin();

        $action = $request->string('action');

        $result = match ($action) {
            'sweep_leases'   => ['released' => BrowserWorker::releaseExpiredLeases()],
            'offline'        => ['offline' => BrowserWorker::sweepOffline(Config::int('security.worker_offline_after_s', 120))],
            'housekeeping'   => \App\Services\SchedulerService::housekeeping(),
            'scheduler_tick' => \App\Services\SchedulerService::tick(true),
            default          => throw HttpException::badRequest('Unsupported maintenance action.'),
        };

        ActivityLog::record(Auth::id(), 'admin.maintenance_' . $action, 'system', null, $request->ip(), 'user', Auth::id(), $result);

        return $this->json(['action' => $action, 'result' => $result]);
    }

    private function assertAdmin(): void
    {
        if (!Auth::isAdmin()) {
            throw HttpException::forbidden('Administrator access is required.');
        }
    }

    /** @return list<array<string,mixed>> */
    private static function allWorkers(int $limit): array
    {
        return Database::instance()->select(
            'SELECT w.*, u.email AS user_email,
                    j.status AS current_job_status, j.job_type AS current_job_type
             FROM browser_workers w
             LEFT JOIN users u ON u.id = w.user_id
             LEFT JOIN jobs j ON j.id = w.current_job_id
             ORDER BY w.last_heartbeat_at DESC LIMIT ' . (int) $limit,
            []
        );
    }

    /** @return array<string,mixed> */
    private static function queueHealth(): array
    {
        $db = Database::instance();
        return [
            'depth'      => (int) $db->scalar("SELECT COUNT(*) FROM jobs WHERE status IN ('SCHEDULED','QUEUED','RETRYING')", []),
            'in_flight'  => (int) $db->scalar("SELECT COUNT(*) FROM jobs WHERE status IN ('CLAIMED','PROCESSING','UPLOADING','PUBLISHING','VERIFYING')", []),
            'stuck'      => (int) $db->scalar(
                "SELECT COUNT(*) FROM jobs WHERE status IN ('CLAIMED','PROCESSING','UPLOADING','PUBLISHING','VERIFYING') AND lease_expires_at < ?",
                [Clock::nowString()]
            ),
            'action_required' => (int) $db->scalar("SELECT COUNT(*) FROM jobs WHERE status IN ('USER_ACTION_REQUIRED','ACCOUNT_REAUTH_REQUIRED')", []),
            'oldest_queued'   => $db->scalar("SELECT MIN(scheduled_at) FROM jobs WHERE status IN ('SCHEDULED','QUEUED','RETRYING')", []),
            'failed_24h'      => (int) $db->scalar(
                "SELECT COUNT(*) FROM jobs WHERE status = 'FAILED' AND updated_at >= ?",
                [Clock::now()->modify('-24 hours')->format('Y-m-d H:i:s')]
            ),
        ];
    }

    /** @return array<string,mixed> */
    private static function storageBreakdown(): array
    {
        $db = Database::instance();
        return [
            'media_bytes'   => (int) $db->scalar('SELECT COALESCE(SUM(size_bytes),0) FROM media WHERE deleted_at IS NULL', []),
            'media_files'   => (int) $db->scalar('SELECT COUNT(*) FROM media WHERE deleted_at IS NULL', []),
            'soft_deleted'  => (int) $db->scalar('SELECT COUNT(*) FROM media WHERE deleted_at IS NOT NULL', []),
            'worker_logs'   => (int) $db->scalar('SELECT COUNT(*) FROM worker_logs', []),
            'activity_logs' => (int) $db->scalar('SELECT COUNT(*) FROM activity_logs', []),
        ];
    }
}
