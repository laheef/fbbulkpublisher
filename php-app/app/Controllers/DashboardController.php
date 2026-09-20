<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Clock;
use App\Core\Request;
use App\Core\Response;
use App\Models\BrowserWorker;
use App\Models\FacebookAccount;
use App\Models\FacebookPage;
use App\Models\Job;
use App\Models\Notification;
use App\Models\Post;
use App\Services\AnalyticsService;

final class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $userId = $this->requireUserId();

        $accounts = FacebookAccount::summaryForUser($userId);
        $pages = FacebookPage::summaryForUser($userId);
        $posts = Post::summaryForUser($userId);
        $jobs = Job::summaryForUser($userId);
        $workers = BrowserWorker::fleetSummary($userId);

        return $this->view('dashboard/index', [
            'title'      => 'Dashboard',
            'accounts'   => $accounts,
            'pages'      => $pages,
            'posts'      => $posts,
            'jobs'       => $jobs,
            'workers'    => $workers,
            'recentJobs' => Job::forUser($userId, [], 8),
            'activity'   => \App\Models\ActivityLog::forUser($userId, 8),
            'notifications' => Notification::forUser($userId, 5, true),
            'throughput' => AnalyticsService::dailyThroughput($userId, 14),
            'needsSetup' => $accounts['connected'] === 0 || $workers['online'] === 0,
            'nextUp'     => self::nextScheduled($userId),
        ]);
    }

    /** @return list<array<string,mixed>> */
    private static function nextScheduled(int $userId): array
    {
        return \App\Core\Database::instance()->select(
            "SELECT j.id, j.scheduled_at, j.status, j.job_type, f.page_name, p.caption
             FROM jobs j
             JOIN facebook_pages f ON f.id = j.page_id
             JOIN posts p ON p.id = j.post_id
             WHERE j.user_id = ? AND j.status IN ('SCHEDULED','QUEUED','RETRYING')
             ORDER BY j.scheduled_at ASC LIMIT 6",
            [$userId]
        );
    }

    /**
     * JSON status feed consumed by the Windows desktop dashboard (§41) and by
     * the tray icon. Authenticated with the normal session cookie.
     */
    public function apiStatus(Request $request): Response
    {
        $userId = $this->requireUserId();

        return $this->json([
            'server_time_utc' => Clock::nowString(),
            'timezone'        => Auth::timezone(),
            'accounts'        => FacebookAccount::summaryForUser($userId),
            'pages'           => FacebookPage::summaryForUser($userId),
            'posts'           => Post::summaryForUser($userId),
            'jobs'            => Job::summaryForUser($userId),
            'workers'         => BrowserWorker::fleetSummary($userId),
            'unread'          => Notification::unreadCount($userId),
            'counts'          => [
                'pending'         => Job::summaryForUser($userId)['queued'],
                'publishing'      => Job::summaryForUser($userId)['publishing'],
                'action_required' => Job::summaryForUser($userId)['action_required'],
            ],
        ]);
    }
}
