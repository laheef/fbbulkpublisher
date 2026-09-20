<?php
declare(strict_types=1);
use App\Core\Support;
use App\Core\Ui;

/** @var array $page @var array $account @var array|null $worker @var list<array> $recent @var array $stats */
?>
<div class="page-head">
    <div class="page-head__text">
        <h1><?= Support::e((string) $page['page_name']) ?> <?= Ui::status((string) $page['status']) ?></h1>
        <p class="page-head__sub">
            <span class="mono small"><?= Support::e((string) $page['page_id']) ?></span>
            <?php if (!empty($page['category'])): ?> · <?= Support::e((string) $page['category']) ?><?php endif; ?>
            · <a href="<?= Support::e((string) ($page['page_url'] ?? '#')) ?>" target="_blank" rel="noopener noreferrer">open on Facebook</a>
        </p>
    </div>
    <div class="page-head__actions">
        <a class="btn btn--primary" href="/posts/new?page=<?= (int) $page['id'] ?>"><?= Ui::icon('plus', 16) ?> New post to this Page</a>
        <button class="btn" data-action="/pages/<?= (int) $page['id'] ?>/refresh" data-reload="true"
                data-success="Refresh requested — your worker will re-sync Pages."><?= Ui::icon('refresh', 16) ?> Refresh</button>
        <button class="btn" data-action="/pages/<?= (int) $page['id'] ?>/verify" data-reload="true"
                data-success="Verification requested."><?= Ui::icon('check', 16) ?> Verify</button>
        <button class="btn" data-action="/pages/<?= (int) $page['id'] ?>/toggle" data-reload="true">
            <?= Ui::icon('pause', 16) ?> <?= $page['status'] === 'DISABLED' ? 'Enable' : 'Disable' ?>
        </button>
    </div>
</div>

<div class="grid grid--4 mb-2">
    <div class="card"><div class="card__body"><div class="stat">
        <span class="stat__label">Jobs</span><span class="stat__value"><?= (int) $stats['total'] ?></span>
    </div></div></div>
    <div class="card"><div class="card__body"><div class="stat stat--ok">
        <span class="stat__label">Published</span><span class="stat__value"><?= (int) $stats['published'] ?></span>
    </div></div></div>
    <div class="card"><div class="card__body"><div class="stat stat--brand">
        <span class="stat__label">Pending</span><span class="stat__value"><?= (int) $stats['pending'] ?></span>
    </div></div></div>
    <div class="card"><div class="card__body"><div class="stat stat--bad">
        <span class="stat__label">Failed</span><span class="stat__value"><?= (int) $stats['failed'] ?></span>
    </div></div></div>
</div>

<div class="grid grid--sidebar">
    <section class="card">
        <div class="card__head"><h2>Recent jobs for this Page</h2></div>
        <div class="card__body card__body--flush">
            <?php if ($recent === []): ?>
                <?= Ui::emptyState('Nothing has been published here yet', '', 'queue') ?>
            <?php else: ?>
                <div class="table-scroll">
                    <table>
                        <thead><tr><th>#</th><th>Content</th><th>Status</th><th>Result</th><th>When</th></tr></thead>
                        <tbody>
                        <?php foreach ($recent as $job): ?>
                            <tr>
                                <td class="mono">#<?= (int) $job['id'] ?></td>
                                <td><span class="truncate"><?= Support::e(Support::excerpt((string) ($job['caption'] ?? ''), 60)) ?></span></td>
                                <td><?= Ui::status((string) $job['status']) ?></td>
                                <td class="small">
                                    <?php if (!empty($job['result_url'])): ?>
                                        <a href="<?= Support::e((string) $job['result_url']) ?>" target="_blank" rel="noopener noreferrer">view</a>
                                    <?php elseif (!empty($job['error_code'])): ?>
                                        <span class="pill pill--bad tiny"><?= Support::e(Ui::humanize((string) $job['error_code'])) ?></span>
                                    <?php else: ?><span class="soft">—</span><?php endif; ?>
                                </td>
                                <td class="small muted nowrap"><?= Support::e(Ui::ago((string) $job['updated_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <div class="stack">
        <section class="card">
            <div class="card__head"><h2>Connection</h2></div>
            <div class="card__body">
                <dl class="kv">
                    <dt>Account</dt><dd><a href="/accounts/<?= (int) $account['id'] ?>"><?= Support::e((string) $account['label']) ?></a></dd>
                    <dt>Machine</dt>
                    <dd>
                        <?php if ($worker !== null): ?>
                            <a href="/workers/<?= (int) $worker['id'] ?>"><?= Support::e((string) $worker['name']) ?></a>
                            <?= Ui::status((string) $worker['status']) ?>
                        <?php else: ?><span class="soft">none</span><?php endif; ?>
                    </dd>
                    <dt>Last verified</dt><dd><?= Support::e($page['last_verified_at'] === null ? 'never' : Ui::ago((string) $page['last_verified_at'])) ?></dd>
                    <dt>Last published</dt><dd><?= Support::e($page['last_published_at'] === null ? 'never' : Ui::ago((string) $page['last_published_at'])) ?></dd>
                </dl>
            </div>
        </section>

        <section class="card">
            <div class="card__head"><h2>Recent posts</h2></div>
            <div class="card__body">
                <a class="btn btn--block" href="/posts?status=PUBLISHED"><?= Ui::icon('posts', 15) ?> Published posts</a>
                <a class="btn btn--block mt-1" href="/queue?page=<?= (int) $page['id'] ?>"><?= Ui::icon('queue', 15) ?> Queue for this Page</a>
                <a class="btn btn--block mt-1" href="/calendar?page=<?= (int) $page['id'] ?>"><?= Ui::icon('calendar', 15) ?> Calendar</a>
            </div>
        </section>
    </div>
</div>
