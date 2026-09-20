<?php
declare(strict_types=1);
use App\Core\Support;
use App\Core\Ui;

/** @var array $accounts @var array $pages @var array $posts @var array $jobs @var array $workers */
/** @var list<array> $recentJobs @var list<array> $activity @var list<array> $notifications */
/** @var list<array> $throughput @var bool $needsSetup @var list<array> $nextUp */
?>
<div class="page-head">
    <div class="page-head__text">
        <h1>Dashboard</h1>
        <p class="page-head__sub">
            Everything scheduled and publishing across your authorised Pages.
            <?php if ($needsSetup): ?>
                <strong class="soft">Finish setup to start publishing.</strong>
            <?php endif; ?>
        </p>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="/accounts"><?= Ui::icon('accounts', 16) ?> Connect account</a>
        <a class="btn btn--primary" href="/posts/new"><?= Ui::icon('plus', 16) ?> New post</a>
    </div>
</div>

<?php if ($needsSetup): ?>
    <div class="alert alert--info mb-2">
        <?= Ui::icon('shield', 18) ?>
        <div>
            <strong>Complete the two setup steps</strong>
            <p class="small mb-0 mt-1">
                <strong>1.</strong> Install LinkEasy Publisher on your Windows PC and connect this workspace
                (Workers → keeps a machine tied to this account).<br>
                <strong>2.</strong> Use <em>Connect account</em> to open a browser window on that machine and sign
                in to Facebook by hand. Your Pages appear here automatically once signed in.
            </p>
        </div>
    </div>
<?php endif; ?>

<div class="grid grid--4 mb-2">
    <div class="card"><div class="card__body"><div class="stat">
        <span class="stat__label">Connected accounts</span>
        <span class="stat__value"><?= (int) $accounts['connected'] ?></span>
        <span class="stat__meta"><?= (int) $accounts['total'] ?> total<?= $accounts['action'] > 0 ? ' · ' . (int) $accounts['action'] . ' need attention' : '' ?></span>
    </div></div></div>

    <div class="card"><div class="card__body"><div class="stat">
        <span class="stat__label">Connected pages</span>
        <span class="stat__value"><?= (int) $pages['enabled'] ?></span>
        <span class="stat__meta"><?= (int) $pages['total'] ?> discovered · <?= (int) $pages['disabled'] ?> disabled</span>
    </div></div></div>

    <div class="card"><div class="card__body"><div class="stat">
        <span class="stat__label">Scheduled posts</span>
        <span class="stat__value"><?= (int) $posts['scheduled'] + (int) $posts['queued'] ?></span>
        <span class="stat__meta"><?= (int) $posts['drafts'] ?> drafts</span>
    </div></div></div>

    <div class="card"><div class="card__body"><div class="stat stat--warn">
        <span class="stat__label">Action required</span>
        <span class="stat__value" data-stat="action_required"><?= (int) $jobs['action_required'] ?></span>
        <span class="stat__meta">Facebook verification or sign-in</span>
    </div></div></div>
</div>

<div class="grid grid--4 mb-2">
    <div class="card"><div class="card__body"><div class="stat stat--brand">
        <span class="stat__label">Queued jobs</span>
        <span class="stat__value" data-stat="queued"><?= (int) $jobs['queued'] ?></span>
        <span class="stat__meta"><?= (int) $jobs['retrying'] ?> retrying</span>
    </div></div></div>

    <div class="card"><div class="card__body"><div class="stat stat--brand">
        <span class="stat__label">Publishing now</span>
        <span class="stat__value" data-stat="publishing"><?= (int) $jobs['publishing'] ?></span>
        <span class="stat__meta">in the browser on your machine</span>
    </div></div></div>

    <div class="card"><div class="card__body"><div class="stat stat--ok">
        <span class="stat__label">Published today</span>
        <span class="stat__value"><?= (int) $jobs['published_today'] ?></span>
        <span class="stat__meta"><?= (int) $jobs['published'] ?> all time</span>
    </div></div></div>

    <div class="card"><div class="card__body"><div class="stat stat--bad">
        <span class="stat__label">Failed</span>
        <span class="stat__value" data-stat="failed"><?= (int) $jobs['failed'] ?></span>
        <span class="stat__meta"><?= (int) $jobs['total'] ?> jobs total</span>
    </div></div></div>
</div>

<div class="grid grid--sidebar">
    <div class="stack">
        <section class="card">
            <div class="card__head">
                <h2>Next up</h2>
                <span class="spacer"></span>
                <a class="btn btn--sm" href="/queue">Open queue</a>
            </div>
            <div class="card__body card__body--flush">
                <?php if ($nextUp === []): ?>
                    <?= Ui::emptyState('Nothing is waiting to publish', 'Schedule a post and it will appear here with a countdown.', 'clock', '/posts/new', 'Create a post') ?>
                <?php else: ?>
                    <div class="table-scroll">
                        <table>
                            <thead><tr><th>Page</th><th>Content</th><th>Status</th><th>When</th></tr></thead>
                            <tbody>
                            <?php foreach ($nextUp as $job): ?>
                                <tr>
                                    <td><strong><?= Support::e((string) $job['page_name']) ?></strong></td>
                                    <td><span class="truncate"><?= Support::e(Support::excerpt((string) $job['caption'], 70)) ?></span></td>
                                    <td><?= Ui::status((string) $job['status']) ?></td>
                                    <td class="nowrap small">
                                        <?= Support::e(Ui::localTime((string) $job['scheduled_at'], $timezone, 'M j, H:i')) ?>
                                        <span class="soft">· <?= Support::e(Ui::until((string) $job['scheduled_at'])) ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="card">
            <div class="card__head">
                <h2>Recent activity</h2>
                <span class="spacer"></span>
                <a class="btn btn--sm" href="/posts">All posts</a>
            </div>
            <div class="card__body card__body--flush">
                <?php if ($recentJobs === []): ?>
                    <?= Ui::emptyState('No publishing history yet', 'Once a job runs, every state change is recorded here.', 'queue') ?>
                <?php else: ?>
                    <div class="table-scroll">
                        <table>
                            <thead><tr><th>Job</th><th>Page</th><th>Worker</th><th>Status</th><th>Result</th><th>Updated</th></tr></thead>
                            <tbody>
                            <?php foreach ($recentJobs as $job): ?>
                                <tr>
                                    <td class="mono">#<?= (int) $job['id'] ?></td>
                                    <td>
                                        <?= Support::e((string) $job['page_name']) ?>
                                        <span class="soft tiny"><?= Support::e(Ui::humanize((string) $job['job_type'])) ?></span>
                                    </td>
                                    <td class="small muted"><?= Support::e((string) ($job['worker_name'] ?? '—')) ?></td>
                                    <td><?= Ui::status((string) $job['status']) ?></td>
                                    <td class="small">
                                        <?php if (!empty($job['result_url'])): ?>
                                            <a href="<?= Support::e((string) $job['result_url']) ?>" target="_blank" rel="noopener noreferrer">Open post</a>
                                        <?php elseif (!empty($job['error_code'])): ?>
                                            <span class="pill pill--bad"><?= Support::e(Ui::humanize((string) $job['error_code'])) ?></span>
                                        <?php else: ?>
                                            <span class="soft">—</span>
                                        <?php endif; ?>
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
    </div>

    <div class="stack">
        <section class="card">
            <div class="card__head"><h2>Publishing rhythm</h2></div>
            <div class="card__body">
                <?= Ui::barChart($throughput) ?>
                <div class="row row--between small muted mt-1">
                    <span>14 days ago</span>
                    <span class="row" style="gap:12px">
                        <span><span class="dot" style="background:#34d399"></span> published</span>
                        <span><span class="dot" style="background:#f87171"></span> failed</span>
                    </span>
                    <span>today</span>
                </div>
                <hr class="hr">
                <dl class="kv">
                    <dt>Success rate</dt>
                    <dd><?= (int) $jobs['published'] > 0 ? 'healthy' : '—' ?></dd>
                    <dt>Retrying</dt>
                    <dd><?= (int) $jobs['retrying'] ?> jobs</dd>
                    <dt>Paused</dt>
                    <dd><?= (int) $jobs['paused_noop'] ?> jobs</dd>
                </dl>
            </div>
        </section>

        <section class="card">
            <div class="card__head">
                <h2>Automation engine</h2>
                <span class="spacer"></span>
                <a class="btn btn--sm" href="/workers">Manage</a>
            </div>
            <div class="card__body">
                <dl class="kv">
                    <dt>Workers online</dt>
                    <dd>
                        <?php if ($workers['online'] > 0): ?>
                            <span class="pill pill--ok"><span class="dot dot--live"></span> <?= (int) $workers['online'] ?> online</span>
                        <?php else: ?>
                            <span class="pill pill--bad">none online</span>
                        <?php endif; ?>
                    </dd>
                    <dt>Machines</dt>
                    <dd><?= (int) $workers['total'] ?> registered</dd>
                    <dt>Job capacity</dt>
                    <dd><?= (int) $workers['capacity'] ?> concurrent</dd>
                    <dt>Worker queue</dt>
                    <dd><?= (int) $workers['pending_jobs'] ?> in hand</dd>
                </dl>
                <?php if ($workers['online'] === 0): ?>
                    <div class="alert alert--warn mt-2">
                        <?= Ui::icon('alert', 16) ?>
                        <div class="small">
                            No Windows machine is connected, so queued jobs will wait.
                            Open <strong>LinkEasy Publisher</strong> on your PC — it reconnects automatically.
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($notifications !== []): ?>
            <section class="card">
                <div class="card__head">
                    <h2>Needs your attention</h2>
                    <span class="spacer"></span>
                    <a class="btn btn--sm" href="/notifications">All</a>
                </div>
                <div class="card__body">
                    <ul class="timeline">
                        <?php foreach ($notifications as $note): ?>
                            <li class="<?= $note['level'] === 'action_required' ? 'is-warn' : ($note['level'] === 'success' ? 'is-ok' : 'is-bad') ?>">
                                <strong><?= Support::e((string) $note['title']) ?></strong>
                                <p class="small muted mb-0"><?= Support::e(Support::excerpt((string) $note['body'], 120)) ?></p>
                                <span class="soft tiny"><?= Support::e(Ui::ago((string) $note['created_at'])) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </section>
        <?php endif; ?>

        <section class="card">
            <div class="card__head"><h2>Recent operator activity</h2></div>
            <div class="card__body">
                <?php if ($activity === []): ?>
                    <p class="muted small mb-0">Nothing logged yet.</p>
                <?php else: ?>
                    <ul class="timeline">
                        <?php foreach ($activity as $entry): ?>
                            <li>
                                <span class="mono tiny"><?= Support::e((string) $entry['action']) ?></span>
                                <p class="small muted mb-0"><?= Support::e(Ui::ago((string) $entry['created_at'])) ?></p>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>

<div data-poll="20000" data-poll-source="/api/status"></div>
