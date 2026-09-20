<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Clock;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

/**
 * Month view of everything scheduled, always rendered in the user's timezone.
 */
final class CalendarController extends Controller
{
    public function index(Request $request): Response
    {
        $userId = $this->requireUserId();
        $timezone = Auth::timezone();

        $month = $request->string('month', Clock::now()->format('Y-m'));
        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            $month = Clock::now()->format('Y-m');
        }

        $tz = new \DateTimeZone($timezone);
        $first = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $month . '-01 00:00:00', $tz);
        if ($first === false) {
            throw HttpException::badRequest('That month could not be interpreted.');
        }

        $last = $first->modify('last day of this month');

        // Query the UTC window that covers the local month, so a post at
        // 23:30 local still lands on the right calendar day.
        $utcStart = $first->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $utcEnd = $last->modify('+1 day')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $jobs = Database::instance()->select(
            "SELECT j.id, j.status, j.job_type, j.scheduled_at, j.completed_at, j.result_url,
                    f.page_name, f.id AS page_row_id, p.caption, p.kind AS post_kind, p.id AS post_id,
                    a.label AS account_label
             FROM jobs j
             JOIN facebook_pages f ON f.id = j.page_id
             JOIN posts p ON p.id = j.post_id
             JOIN facebook_accounts a ON a.id = j.account_id
             WHERE j.user_id = ? AND j.scheduled_at >= ? AND j.scheduled_at < ?
             ORDER BY j.scheduled_at ASC",
            [$userId, $utcStart, $utcEnd]
        );

        $byDay = [];
        foreach ($jobs as $job) {
            $localDate = Clock::utcToLocal((string) $job['scheduled_at'], $timezone, 'Y-m-d');
            $job['local_time'] = Clock::utcToLocal((string) $job['scheduled_at'], $timezone, 'H:i');
            $byDay[$localDate][] = $job;
        }

        // Build the grid (weeks start on Monday).
        $gridStart = $first->modify('monday this week');
        $gridEnd = $last->modify('sunday this week');
        $weeks = [];
        $cursor = $gridStart;
        while ($cursor <= $gridEnd) {
            $week = [];
            for ($i = 0; $i < 7; $i++) {
                $date = $cursor->format('Y-m-d');
                $week[] = [
                    'date'    => $date,
                    'day'     => (int) $cursor->format('j'),
                    'in_month' => $cursor->format('m') === $first->format('m'),
                    'is_today' => $date === Clock::now()->setTimezone($tz)->format('Y-m-d'),
                    'jobs'    => $byDay[$date] ?? [],
                ];
                $cursor = $cursor->modify('+1 day');
            }
            $weeks[] = $week;
        }

        return $this->view('calendar/index', [
            'title'      => 'Calendar',
            'weeks'      => $weeks,
            'monthLabel' => $first->format('F Y'),
            'prevMonth'  => $first->modify('-1 month')->format('Y-m'),
            'nextMonth'  => $first->modify('+1 month')->format('Y-m'),
            'timezone'   => $timezone,
            'total'      => count($jobs),
            'schedules'  => Database::instance()->select(
                "SELECT s.*, p.caption FROM scheduled_posts s JOIN posts p ON p.id = s.post_id
                 WHERE s.user_id = ? AND s.status IN ('SCHEDULED','PAUSED') ORDER BY s.next_run_at ASC LIMIT 100",
                [$userId]
            ),
        ]);
    }
}
