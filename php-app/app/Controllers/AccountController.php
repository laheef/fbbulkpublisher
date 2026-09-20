<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Clock;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\BrowserWorker;
use App\Models\FacebookAccount;
use App\Models\FacebookPage;

/**
 * Facebook account (browser profile) management.
 *
 * The dashboard never sees credentials: connecting is a browser task performed
 * on the operator's own machine. Here we only create the bookkeeping row, ask
 * a worker to open the profile, and record what the worker reports back.
 */
final class AccountController extends Controller
{
    public function index(Request $request): Response
    {
        $userId = $this->requireUserId();
        $accounts = FacebookAccount::forUser($userId);

        foreach ($accounts as &$account) {
            $account['pages'] = FacebookPage::forAccount((int) $account['id']);
        }
        unset($account);

        return $this->view('accounts/index', [
            'title'    => 'Accounts',
            'accounts' => $accounts,
            'workers'  => BrowserWorker::forUser($userId),
        ]);
    }

    /** Create a new browser profile placeholder and queue a connect task. */
    public function store(Request $request): Response
    {
        $userId = $this->requireUserId();

        $validator = Validator::make($request->all(), ['label' => 'Account label'])->required('label')->max('label', 120);
        if ($validator->fails()) {
            throw HttpException::validation('Please name the account.', $validator->errors());
        }

        $label = (string) $validator->validated()['label'];

        // Attach to a worker when the operator has one online; otherwise the
        // profile waits and connects as soon as a machine checks in.
        $workerId = $request->int('worker_id', 0);
        if ($workerId > 0) {
            $worker = $this->owned(BrowserWorker::find($workerId), 'worker');
            $workerId = (int) $worker['id'];
        } else {
            $online = BrowserWorker::onlineForUser($userId);
            $workerId = $online === [] ? 0 : (int) $online[0]['id'];
        }

        $account = FacebookAccount::create([
            'user_id'     => $userId,
            'label'       => $label,
            'profile_ref' => FacebookAccount::newProfileRef(),
            'worker_id'   => $workerId > 0 ? $workerId : null,
            'status'      => 'DISCONNECTED',
        ]);

        if ($account === null) {
            throw new HttpException(500, 'The account could not be created.');
        }

        $this->log('account.created', 'facebook_account', (int) $account['id'], ['label' => $label]);

        return $this->json([
            'account' => $account,
            'next'    => [
                'step'    => 'connect_facebook',
                'message' => $workerId > 0
                    ? 'Your worker will open a Chromium window. Sign in to Facebook there, then return here.'
                    : 'No Windows worker is online yet. Install LinkEasy Publisher on your PC, then click Connect.',
            ],
            'connect_url' => '/accounts/' . (int) $account['id'] . '/connect',
        ]);
    }

    public function show(Request $request, array $params): Response
    {
        $userId = $this->requireUserId();
        $accountId = $this->paramInt($params, 'id', 'account');
        $account = $this->owned(FacebookAccount::find($accountId), 'account');

        return $this->view('accounts/show', [
            'title'   => $account['label'],
            'account' => $account,
            'pages'   => FacebookPage::forAccount($accountId),
            'workers' => BrowserWorker::forUser($userId),
        ]);
    }

    /**
     * Ask the worker to open the persistent profile in a visible browser so the
     * operator can complete a normal Facebook sign-in (including 2FA).
     * We never automate, store or transmit the credentials.
     */
    public function connect(Request $request, array $params): Response
    {
        $this->requireUserId();
        $accountId = $this->paramInt($params, 'id', 'account');
        $account = $this->owned(FacebookAccount::find($accountId), 'account');

        if (empty($account['worker_id'])) {
            throw HttpException::conflict('Attach a Windows worker to this account first, then try again.');
        }

        $worker = BrowserWorker::find((int) $account['worker_id']);
        if ($worker === null || !in_array((string) $worker['status'], ['ONLINE', 'BUSY', 'STARTING'], true)) {
            throw HttpException::conflict('The worker for this account is offline. Open LinkEasy Publisher on that PC and try again.');
        }

        Database::instance()->update('facebook_accounts', ['status' => 'DISCONNECTED'], ['id' => $accountId]);

        // The desktop app polls /accounts and performs the browser work; the web
        // UI simply tells the operator what to do next.
        $this->log('account.connect_requested', 'facebook_account', $accountId);

        return $this->json([
            'account_id' => $accountId,
            'status'     => 'CONNECT_REQUESTED',
            'instructions' => 'A Chromium window will open on your PC. Sign in to Facebook there, then click Verify.',
        ]);
    }

    public function verify(Request $request, array $params): Response
    {
        $userId = $this->requireUserId();
        $accountId = $this->paramInt($params, 'id', 'account');
        $account = $this->owned(FacebookAccount::find($accountId), 'account');

        if (empty($account['worker_id'])) {
            throw HttpException::conflict('This account has no worker attached.');
        }

        Database::instance()->update('facebook_accounts', ['status' => 'CONNECTED'], ['id' => $accountId]);

        $this->log('account.verify_requested', 'facebook_account', $accountId);

        return $this->redirect('/accounts/' . $accountId);
    }

    public function update(Request $request, array $params): Response
    {
        $this->requireUserId();
        $accountId = $this->paramInt($params, 'id', 'account');
        $account = $this->owned(FacebookAccount::find($accountId), 'account');

        $validator = Validator::make($request->all(), ['label' => 'Account label'])->required('label')->max('label', 120);
        if ($validator->fails()) {
            throw HttpException::validation('Please name the account.', $validator->errors());
        }

        FacebookAccount::modify($accountId, ['label' => (string) $validator->validated()['label']]);
        $this->log('account.updated', 'facebook_account', $accountId);

        return $this->back('/accounts/' . $accountId);
    }

    public function toggle(Request $request, array $params): Response
    {
        $this->requireUserId();
        $accountId = $this->paramInt($params, 'id', 'account');
        $account = $this->owned(FacebookAccount::find($accountId), 'account');

        $enable = !$request->bool('disable');
        FacebookAccount::modify($accountId, [
            'status' => $enable ? 'DISCONNECTED' : 'DISABLED',
            'last_error_code' => null,
            'last_error' => null,
        ]);

        $this->log($enable ? 'account.enabled' : 'account.disabled', 'facebook_account', $accountId);

        return $this->back('/accounts');
    }

    /** Reset the profile: everything local is discarded, re-auth is required. */
    public function resetProfile(Request $request, array $params): Response
    {
        $this->requireUserId();
        $accountId = $this->paramInt($params, 'id', 'account');
        $account = $this->owned(FacebookAccount::find($accountId), 'account');

        // A new profile reference means the next launch starts from a clean
        // Chromium profile directory — the old one is never reused.
        Database::instance()->update('facebook_accounts', [
            'profile_ref'      => FacebookAccount::newProfileRef(),
            'status'           => 'DISCONNECTED',
            'fb_account_name'  => null,
            'fb_account_id'    => null,
            'last_verified_at' => null,
            'last_error_code'  => null,
            'last_error'       => null,
        ], ['id' => $accountId]);

        Database::instance()->update('facebook_pages', ['status' => 'AUTH_REQUIRED'], ['account_id' => $accountId]);

        $this->log('account.profile_reset', 'facebook_account', $accountId);

        return $this->json([
            'account_id' => $accountId,
            'warning'    => 'The browser profile was reset. You will need to sign in to Facebook again on this machine.',
        ]);
    }

    public function remove(Request $request, array $params): Response
    {
        $this->requireUserId();
        $accountId = $this->paramInt($params, 'id', 'account');
        $this->owned(FacebookAccount::find($accountId), 'account');

        $activeJobs = (int) Database::instance()->scalar(
            "SELECT COUNT(*) FROM jobs WHERE account_id = ? AND status NOT IN ('PUBLISHED','FAILED','CANCELLED')",
            [$accountId]
        );

        if ($activeJobs > 0 && !$request->bool('force')) {
            throw HttpException::conflict(
                "This account still has {$activeJobs} active job(s). Cancel or finish them first, or confirm to remove anyway.",
                ['active_jobs' => (string) $activeJobs, 'confirm_required' => '1']
            );
        }

        Database::instance()->query('DELETE FROM facebook_accounts WHERE id = ?', [$accountId]);
        $this->log('account.removed', 'facebook_account', $accountId, ['active_jobs_cancelled' => $activeJobs]);

        return $this->redirect('/accounts');
    }

    /** Per-page toggles live here because they belong to the account view. */
    public function selectPages(Request $request, array $params): Response
    {
        $userId = $this->requireUserId();
        $accountId = $this->paramInt($params, 'id', 'account');
        $this->owned(FacebookAccount::find($accountId), 'account');

        $enabledIds = array_map('intval', $request->arrayOfStrings('enabled_page_ids'));

        foreach (FacebookPage::forAccount($accountId) as $page) {
            $shouldEnable = in_array((int) $page['id'], $enabledIds, true);
            $currentStatus = (string) $page['status'];

            if ($shouldEnable && $currentStatus === 'DISABLED') {
                FacebookPage::setEnabled((int) $page['id'], true);
            } elseif (!$shouldEnable && $currentStatus === 'ENABLED') {
                FacebookPage::setEnabled((int) $page['id'], false);
            }
        }

        $this->log('account.pages_updated', 'facebook_account', $accountId, ['enabled' => count($enabledIds)]);

        return $this->redirect('/accounts/' . $accountId);
    }
}
