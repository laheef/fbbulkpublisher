<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Clock;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\BrowserWorker;
use App\Models\FacebookAccount;
use App\Models\FacebookPage;
use App\Models\Job;

final class PageController extends Controller
{
    public function index(Request $request): Response
    {
        $userId = $this->requireUserId();

        $filters = [
            'search' => $request->string('q'),
            'status' => $request->string('status'),
            'account' => $request->int('account'),
        ];

        $pages = FacebookPage::forUser($userId);

        if ($filters['search'] !== '') {
            $needle = mb_strtolower($filters['search']);
            $pages = array_values(array_filter($pages, static fn (array $p): bool =>
                str_contains(mb_strtolower((string) $p['page_name']), $needle)
                || str_contains(mb_strtolower((string) $p['page_id']), $needle)));
        }
        if ($filters['status'] !== '') {
            $pages = array_values(array_filter($pages, static fn (array $p): bool => $p['status'] === $filters['status']));
        }
        if ($filters['account'] > 0) {
            $pages = array_values(array_filter($pages, static fn (array $p): bool => (int) $p['account_id'] === $filters['account']));
        }

        return $this->view('pages/index', [
            'title'    => 'Pages',
            'pages'    => $pages,
            'filters'  => $filters,
            'accounts' => FacebookAccount::forUser($userId),
            'summary'  => FacebookPage::summaryForUser($userId),
            'statuses' => FacebookPage::STATUSES,
        ]);
    }

    public function show(Request $request, array $params): Response
    {
        $this->requireUserId();
        $pageId = $this->paramInt($params, 'id', 'Page');
        $page = $this->owned(FacebookPage::find($pageId), 'Page');
        $account = $this->owned(FacebookAccount::find((int) $page['account_id']), 'account');

        $recent = Database::instance()->select(
            'SELECT j.*, p.caption, p.kind AS post_kind
             FROM jobs j JOIN posts p ON p.id = j.post_id
             WHERE j.page_id = ? ORDER BY j.created_at DESC LIMIT 25',
            [$pageId]
        );

        return $this->view('pages/show', [
            'title'     => $page['page_name'],
            'page'      => $page,
            'account'   => $account,
            'worker'    => $page['account_id'] ? BrowserWorker::find((int) $account['worker_id']) : null,
            'recent'    => $recent,
            'stats'     => self::stats($pageId),
        ]);
    }

    public function toggle(Request $request, array $params): Response
    {
        $this->requireUserId();
        $pageId = $this->paramInt($params, 'id', 'Page');
        $page = $this->owned(FacebookPage::find($pageId), 'Page');

        $enable = (string) $page['status'] === 'DISABLED'
            ? true
            : ($request->bool('disable') ? false : true);

        FacebookPage::setEnabled($pageId, $enable);
        $this->log($enable ? 'page.enabled' : 'page.disabled', 'facebook_page', $pageId);

        if ($request->expectsJson()) {
            $updated = FacebookPage::find($pageId);
            return $this->json(['page' => $updated]);
        }

        return $this->back('/pages');
    }

    /** Ask the worker to re-confirm access to this Page using the live session. */
    public function verify(Request $request, array $params): Response
    {
        $this->requireUserId();
        $pageId = $this->paramInt($params, 'id', 'Page');
        $page = $this->owned(FacebookPage::find($pageId), 'Page');

        $account = FacebookAccount::find((int) $page['account_id']);
        if ($account === null || empty($account['worker_id'])) {
            throw HttpException::conflict('No Windows worker is attached to this Page.');
        }

        FacebookPage::modify($pageId, ['last_verified_at' => Clock::nowString()]);
        $this->log('page.verify_requested', 'facebook_page', $pageId);

        return $this->json([
            'page_id' => $pageId,
            'status'  => 'VERIFY_REQUESTED',
            'message' => 'Your worker will re-check this Page on the next cycle.',
        ]);
    }

    /** Re-sync the Page list for the account this Page belongs to. */
    public function refresh(Request $request, array $params): Response
    {
        $this->requireUserId();
        $pageId = $this->paramInt($params, 'id', 'Page');
        $page = $this->owned(FacebookPage::find($pageId), 'Page');

        $this->log('page.refresh_requested', 'facebook_page', $pageId, ['account_id' => $page['account_id']]);

        return $this->json([
            'account_id' => (int) $page['account_id'],
            'message'    => 'Your worker will refresh the Page list for this account shortly.',
        ]);
    }

    /** @return array<string,mixed> */
    private static function stats(int $pageId): array
    {
        $row = Database::instance()->first(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = 'PUBLISHED' THEN 1 ELSE 0 END) AS published,
                    SUM(CASE WHEN status = 'FAILED' THEN 1 ELSE 0 END) AS failed,
                    SUM(CASE WHEN status IN ('SCHEDULED','QUEUED','RETRYING') THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN status IN ('USER_ACTION_REQUIRED','ACCOUNT_REAUTH_REQUIRED') THEN 1 ELSE 0 END) AS action_required
             FROM jobs WHERE page_id = ?",
            [$pageId]
        ) ?? [];

        return [
            'total'           => (int) ($row['total'] ?? 0),
            'published'       => (int) ($row['published'] ?? 0),
            'failed'          => (int) ($row['failed'] ?? 0),
            'pending'         => (int) ($row['pending'] ?? 0),
            'action_required' => (int) ($row['action_required'] ?? 0),
        ];
    }
}
