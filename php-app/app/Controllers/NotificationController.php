<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\Notification;

final class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $userId = $this->requireUserId();

        return $this->view('notifications/index', [
            'title'         => 'Notifications',
            'notifications' => Notification::forUser($userId, 100, $request->bool('unread')),
            'unreadOnly'    => $request->bool('unread'),
            'unreadCount'   => Notification::unreadCount($userId),
        ]);
    }

    public function markRead(Request $request): Response
    {
        $userId = $this->requireUserId();
        $ids = array_map('intval', $request->arrayOfStrings('ids'));
        $affected = Notification::markRead($userId, $ids);

        if ($request->expectsJson()) {
            return $this->json(['marked' => $affected]);
        }

        return $this->redirect('/notifications');
    }

    /**
     * Polling feed for the Windows client, which raises native toast
     * notifications for anything new (§45).
     */
    public function feed(Request $request): Response
    {
        $userId = $this->requireUserId();
        $since = $request->string('since');

        $rows = Notification::since($userId, $since === '' ? null : $since, 20);

        return $this->json([
            'server_time_utc' => \App\Core\Clock::nowString(),
            'unread'          => Notification::unreadCount($userId),
            'notifications'   => array_map(static fn (array $n): array => [
                'id'         => (int) $n['id'],
                'level'      => $n['level'],
                'title'      => $n['title'],
                'body'       => $n['body'],
                'link'       => $n['link'],
                'job_id'     => $n['job_id'] === null ? null : (int) $n['job_id'],
                'created_at' => \App\Core\Clock::iso((string) $n['created_at']),
                'toast'      => in_array((string) $n['level'], ['success', 'warning', 'error', 'action_required'], true),
            ], $rows),
        ]);
    }

    public function clear(Request $request): Response
    {
        $userId = $this->requireUserId();
        Notification::markRead($userId, []);
        Session::flash('success', 'All notifications marked as read.');
        return $this->redirect('/notifications');
    }
}
