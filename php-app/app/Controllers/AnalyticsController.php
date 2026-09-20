<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\AnalyticsService;

final class AnalyticsController extends Controller
{
    public function index(Request $request): Response
    {
        $userId = $this->requireUserId();
        $days = max(1, min(365, $request->int('days', 30)));

        return $this->view('analytics/index', [
            'title'      => 'Analytics',
            'days'       => $days,
            'overview'   => AnalyticsService::overview($userId, $days),
            'failures'   => AnalyticsService::failureCategories($userId, $days),
            'byPage'     => AnalyticsService::byPage($userId, $days),
            'byAccount'  => AnalyticsService::byAccount($userId, $days),
            'byWorker'   => AnalyticsService::workerPerformance($userId, $days),
            'throughput' => AnalyticsService::dailyThroughput($userId, min(30, $days)),
        ]);
    }

    /** JSON endpoint for the desktop analytics widgets and the tray tooltip. */
    public function api(Request $request): Response
    {
        $userId = $this->requireUserId();
        $days = max(1, min(365, $request->int('days', 30)));

        return $this->json([
            'days'       => $days,
            'overview'   => AnalyticsService::overview($userId, $days),
            'failures'   => AnalyticsService::failureCategories($userId, $days),
            'throughput' => AnalyticsService::dailyThroughput($userId, min(30, $days)),
        ]);
    }
}
