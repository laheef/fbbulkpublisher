<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Clock;
use App\Core\Config;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\FacebookAccount;
use App\Models\FacebookPage;
use App\Models\Media;
use App\Models\Post;
use App\Services\PostDispatcher;
use App\Services\Publishing\ProviderFactory;

final class PostController extends Controller
{
    public function index(Request $request): Response
    {
        $userId = $this->requireUserId();

        $filters = [
            'status' => $request->string('status'),
            'kind'   => $request->string('kind'),
            'search' => $request->string('q'),
        ];

        return $this->view('posts/index', [
            'title'    => 'Posts',
            'posts'    => Post::feed($userId, $filters, 60),
            'filters'  => $filters,
            'summary'  => Post::summaryForUser($userId),
            'statuses' => Post::STATUSES,
            'kinds'    => Post::KINDS,
        ]);
    }

    /** The composer. */
    public function create(Request $request): Response
    {
        $userId = $this->requireUserId();

        $prefillPage = $request->int('page', 0);
        $prefillMedia = $request->int('media', 0);

        return $this->view('posts/compose', [
            'title'    => 'New post',
            'accounts' => self::accountsWithPages($userId),
            'media'    => Media::forUser($userId, 60),
            'prefill'  => ['page_id' => $prefillPage, 'media_id' => $prefillMedia, 'kind' => $request->string('kind', 'TEXT')],
            'provider' => ProviderFactory::make()->label(),
            'timezone' => Auth::timezone(),
        ]);
    }

    /**
     * Composer submit. One endpoint handles all three intents — publish now,
     * schedule, or save a draft — because they share all validation.
     */
    public function store(Request $request): Response
    {
        $userId = $this->requireUserId();

        $validator = Validator::make($request->all(), [
            'caption' => 'Caption', 'hashtags' => 'Hashtags', 'link_url' => 'Link',
            'media_id' => 'Media', 'intent' => 'Action',
        ])
            ->text('caption', 20000)
            ->text('hashtags', 1000)
            ->url('link_url')
            ->in('intent', ['publish', 'schedule', 'draft']);

        if ($validator->fails()) {
            throw HttpException::validation('Please review the composer fields.', $validator->errors());
        }

        $data = $validator->validated();
        $pageIds = $request->arrayOfStrings('page_ids');
        $intent = (string) ($data['intent'] ?? 'publish');

        $mediaId = (int) ($data['media_id'] ?? 0);
        $media = $mediaId > 0 ? $this->owned(Media::find($mediaId), 'media file') : null;

        $caption = (string) ($data['caption'] ?? '');
        if (trim($caption) === '' && $media === null) {
            throw HttpException::validation('Add a caption or attach media before continuing.', [
                'caption' => 'A post needs text or media.',
            ]);
        }

        if ($intent !== 'draft' && $pageIds === []) {
            throw HttpException::validation('Select at least one Page.', [
                'page_ids' => 'Choose the Pages that should receive this post.',
            ]);
        }

        // Reject Pages the caller does not own, silently dropping nothing.
        $pages = $pageIds === [] ? [] : FacebookPage::ownedByIds($userId, array_map('intval', $pageIds));
        if ($intent !== 'draft' && count($pages) !== count(array_unique($pageIds))) {
            throw HttpException::validation('One or more selected Pages are not available to you.');
        }

        $kind = $this->deriveKind($caption, $media, $request->string('kind'));
        $timezone = Auth::timezone();

        $post = Post::compose($userId, [
            'kind'     => $kind,
            'caption'  => $caption,
            'hashtags' => $data['hashtags'] ?? null,
            'link_url' => $data['link_url'] ?? null,
            'title'    => $request->string('title'),
            'media_id' => $media === null ? null : (int) $media['id'],
            'provider' => ProviderFactory::make()->key(),
            'settings' => [
                'thumbnail_choice' => $request->string('thumbnail_choice'),
                'notify_on_complete' => $request->bool('notify_on_complete', true),
            ],
        ], $timezone);

        $postId = (int) $post['id'];
        Post::attachPages($postId, $userId, array_map(static fn (array $p): int => (int) $p['id'], $pages));

        if ($intent === 'draft') {
            $this->log('post.drafted', 'post', $postId);
            return $this->json([
                'post_id' => $postId,
                'status'  => 'DRAFT',
                'redirect' => '/posts/' . $postId,
            ]);
        }

        if ($intent === 'schedule') {
            $local = $request->string('scheduled_at_local');
            if ($local === '') {
                throw HttpException::validation('Choose when this post should go out.', [
                    'scheduled_at_local' => 'A date and time is required.',
                ]);
            }

            $utc = Clock::localToUtc($local, $timezone);
            if (Clock::parseUtc($utc) <= Clock::now()) {
                throw HttpException::validation('That time is in the past. Pick a future time, or choose Publish now.', [
                    'scheduled_at_local' => 'Choose a future date and time.',
                ]);
            }

            $schedule = PostDispatcher::schedule($userId, $postId, [
                'timezone'           => $timezone,
                'scheduled_at_local' => $local,
                'recurrence'         => $request->string('recurrence', 'NONE'),
                'interval_value'     => $request->int('interval_value', 0),
                'stagger_seconds'    => $request->int('stagger_seconds', 0),
                'max_runs'           => $request->int('max_runs', 0),
            ]);

            // Materialise the jobs immediately too, so the queue and calendar
            // are accurate before the scheduler's next tick.
            $report = PostDispatcher::dispatch($userId, $postId, array_map(static fn (array $p): int => (int) $p['id'], $pages), [
                'when'            => $utc,
                'stagger_seconds' => $request->int('stagger_seconds', 0),
                'salt'            => 'schedule:' . ($schedule['id'] ?? 0) . ':1',
            ]);

            $this->log('post.scheduled', 'post', $postId, ['jobs' => count($report['job_ids'])]);

            return $this->json([
                'post_id'  => $postId,
                'status'   => 'SCHEDULED',
                'schedule' => $schedule,
                'jobs'     => $report,
                'local_time' => Clock::utcToLocal($utc, $timezone),
                'redirect' => '/posts/' . $postId,
            ]);
        }

        $report = PostDispatcher::dispatch($userId, $postId, array_map(static fn (array $p): int => (int) $p['id'], $pages), [
            'stagger_seconds' => $request->int('stagger_seconds', 0),
        ]);

        $this->log('post.published', 'post', $postId, ['jobs' => count($report['job_ids'])]);

        return $this->json([
            'post_id'  => $postId,
            'status'   => $report['queued'] > 0 ? 'QUEUED' : 'SCHEDULED',
            'dispatched' => $report,
            'redirect' => '/posts/' . $postId,
        ]);
    }

    public function show(Request $request, array $params): Response
    {
        $this->requireUserId();
        $postId = $this->paramInt($params, 'id', 'post');
        $this->owned(Post::find($postId), 'post');

        $detail = Post::detail($postId);
        if ($detail === null) {
            throw HttpException::notFound('That post does not exist.');
        }

        return $this->view('posts/show', [
            'title'   => 'Post #' . $postId,
            'post'    => $detail,
            'schedules' => \App\Core\Database::instance()->select(
                'SELECT * FROM scheduled_posts WHERE post_id = ? ORDER BY created_at DESC',
                [$postId]
            ),
            'events'  => \App\Models\JobEvent::timeline((int) ($detail['jobs'][0]['id'] ?? 0), 100),
        ]);
    }

    public function update(Request $request, array $params): Response
    {
        $userId = $this->requireUserId();
        $postId = $this->paramInt($params, 'id', 'post');
        $post = $this->owned(Post::find($postId), 'post');

        if (!in_array((string) $post['status'], ['DRAFT', 'FAILED', 'CANCELLED', 'PARTIAL'], true)) {
            throw HttpException::conflict('This post already has work in flight. Create a new post instead of editing it.');
        }

        Post::modify($postId, [
            'caption'  => $request->string('caption'),
            'hashtags' => $request->string('hashtags'),
            'link_url' => $request->string('link_url') ?: null,
        ]);

        if ($request->has('page_ids')) {
            $pageIds = array_map('intval', $request->arrayOfStrings('page_ids'));
            $owned = FacebookPage::ownedByIds($userId, $pageIds);
            Post::detachPages($postId, array_map(static fn (array $p): int => (int) $p['id'], Post::targets($postId)));
            Post::attachPages($postId, $userId, array_map(static fn (array $p): int => (int) $p['id'], $owned));
        }

        $this->log('post.updated', 'post', $postId);

        return $this->json(['post' => Post::detail($postId)]);
    }

    /** Re-target or re-run a post — always as new idempotent jobs. */
    public function republish(Request $request, array $params): Response
    {
        $userId = $this->requireUserId();
        $postId = $this->paramInt($params, 'id', 'post');
        $this->owned(Post::find($postId), 'post');

        $pageIds = array_map('intval', $request->arrayOfStrings('page_ids'));
        if ($pageIds === []) {
            $pageIds = array_map(static fn (array $t): int => (int) $t['page_id'], Post::targets($postId));
        }

        $report = PostDispatcher::dispatch($userId, $postId, $pageIds, [
            'force'           => true,      // the operator explicitly wants a second publication
            'stagger_seconds' => $request->int('stagger_seconds', 0),
        ]);

        $this->log('post.republished', 'post', $postId, ['jobs' => count($report['job_ids'])]);

        return $this->json(['dispatched' => $report]);
    }

    public function cancel(Request $request, array $params): Response
    {
        $this->requireUserId();
        $postId = $this->paramInt($params, 'id', 'post');
        $this->owned(Post::find($postId), 'post');

        $jobs = \App\Core\Database::instance()->select(
            "SELECT id FROM jobs WHERE post_id = ? AND status NOT IN ('PUBLISHED','FAILED','CANCELLED')",
            [$postId]
        );
        foreach ($jobs as $job) {
            \App\Models\Job::cancel((int) $job['id']);
        }

        \App\Core\Database::instance()->query(
            "UPDATE scheduled_posts SET status = 'CANCELLED' WHERE post_id = ? AND status IN ('SCHEDULED','PAUSED')",
            [$postId]
        );
        Post::setStatus($postId, 'CANCELLED');

        $this->log('post.cancelled', 'post', $postId, ['jobs_cancelled' => count($jobs)]);

        return $this->json(['cancelled_jobs' => count($jobs)]);
    }

    public function destroy(Request $request, array $params): Response
    {
        $this->requireUserId();
        $postId = $this->paramInt($params, 'id', 'post');
        $post = $this->owned(Post::find($postId), 'post');

        $live = (int) \App\Core\Database::instance()->scalar(
            "SELECT COUNT(*) FROM jobs WHERE post_id = ? AND status NOT IN ('PUBLISHED','FAILED','CANCELLED')",
            [$postId]
        );
        if ($live > 0) {
            throw HttpException::conflict('This post still has jobs in flight. Cancel them first.');
        }

        \App\Core\Database::instance()->query('DELETE FROM posts WHERE id = ?', [$postId]);
        $this->log('post.deleted', 'post', $postId, ['kind' => $post['kind']]);

        return $this->redirect('/posts');
    }

    /** @return list<array<string,mixed>> Accounts with their selectable Pages. */
    private static function accountsWithPages(int $userId): array
    {
        $accounts = FacebookAccount::forUser($userId);
        foreach ($accounts as &$account) {
            $account['pages'] = FacebookPage::forAccount((int) $account['id']);
        }
        unset($account);
        return $accounts;
    }

    private function deriveKind(string $caption, ?array $media, string $requested): string
    {
        if ($media !== null) {
            if ($requested === 'REEL' && (string) $media['kind'] === 'VIDEO') {
                return 'REEL';
            }
            return (string) $media['kind'] === 'VIDEO' ? 'VIDEO' : 'IMAGE';
        }
        return trim($caption) === '' ? 'TEXT' : 'TEXT';
    }
}
