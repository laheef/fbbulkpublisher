<?php
declare(strict_types=1);
use App\Core\Support;
use App\Core\Ui;

/** @var list<array> $jobs @var array $filters @var array $summary @var list<array> $workers @var list<string> $statuses @var array|null $viewing */
?>
<div class="page-head">
    <div class="page-head__text">
        <h1>Queue</h1>
        <p class="page-head__sub">
            The queue lives here. Windows workers claim jobs atomically, hold a lease while running, and report back.
        </p>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="/queue?status=ACTION">
            <?= Ui::icon('alert', 16) ?> Action required (<?= (int) $summary['action_required'] ?>)
        </a>
        <a class="btn" href="/queue?status=ACTIVE"><?= Ui::icon('play', 16) ?> Publishing now</a>
    </div>
</div>

<div class="grid grid--4 mb-2">
    <div class="card"><div class="card__body"><div class="stat stat--brand">
        <span class="stat__label">Queued</span><span class="stat__value"><?= (int) $summary['queued'] ?></span>
    </div></div></div>
    <div class="card"><div class="card__body"><div class="stat stat--brand">
        <span class="stat__label">In flight</span><span class="stat__value"><?= (int) $summary['publishing'] ?></span>
    </div></div></div>
    <div class="card"><div class="card__body"><div class="stat stat--ok">
        <span class="stat__label">Published</span><span class="stat__value"><?= (int) $summary['published'] ?></span>
    </div></div></div>
    <div class="card"><div class="card__body"><div class="stat stat--warn">
        <span class="stat__label">Needs a human</span><span class="stat__value"><?= (int) $summary['action_required'] ?></span>
    </div></div></div>
</div>

<?php if ($viewing !== null): ?>
    <section class="card mb-2">
        <div class="card__head">
            <h2>Job #<?= (int) $viewing['id'] ?></h2>
            <?= Ui::status((string) $viewing['status']) ?>
            <span class="spacer"></span>
            <a class="btn btn--sm" href="/queue"><?= Ui::icon('x', 15) ?> Close</a>
        </div>
        <div class="card__body">
            <div class="grid grid--2">
                <div>
                    <dl class="kv">
                        <dt>Page</dt><dd><a href="/pages/<?= (int) $viewing['page_id'] ?>"><?= Support::e((string) $viewing['page_name']) ?></a></dd>
                        <dt>Account</dt><dd><?= Support::e((string) $viewing['account_label']) ?></dd>
                        <dt>Worker</dt><dd><?= Support::e((string) ($viewing['worker_name'] ?? 'unclaimed')) ?></dd>
                        <dt>Stage</dt><dd><?= Support::e(Ui::humanize((string) ($viewing['stage'] ?? '—'))) ?></dd>
                        <dt>Progress</dt><dd><?= Ui::progress((int) $viewing['progress_pct']) ?> <?= (int) $viewing['progress_pct'] ?>%</dd>
                        <dt>Attempts</dt><dd><?= (int) $viewing['attempts'] ?> / <?= (int) $viewing['max_attempts'] ?></dd>
                        <dt>Scheduled</dt><dd><?= Support::e(Ui::localTime((string) $viewing['scheduled_at'], $timezone, 'M j, Y H:i:s')) ?></dd>
                        <dt>Idempotency key</dt><dd class="mono tiny" style="word-break:break-all"><?= Support::e((string) $viewing['idempotency_key']) ?></dd>
                    </dl>

                    <?php if (!empty($viewing['error_message'])): ?>
                        <div class="alert alert--warn mt-2">
                            <?= Ui::icon('alert', 16) ?>
                            <div class="small">
                                <strong><?= Support::e(Ui::humanize((string) $viewing['error_code'])) ?></strong><br>
                                <?= Support::e((string) $viewing['error_message']) ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($viewing['result_url'])): ?>
                        <p class="mt-2"><a class="btn" href="<?= Support::e((string) $viewing['result_url']) ?>" target="_blank" rel="noopener noreferrer">
                            <?= Ui::icon('external', 15) ?> Open the published post</a></p>
                    <?php endif; ?>

                    <div class="row mt-2">
                        <?php if (in_array((string) $viewing['status'], array_merge(\App\Models\Job::USER_ACTION, ['FAILED', 'CANCELLED', 'PAUSED']), true)): ?>
                            <?php if (in_array((string) $viewing['status'], \App\Models\Job::USER_ACTION, true)): ?>
                                <button class="btn btn--primary btn--sm"
                                        data-action="/queue/<?= (int) $viewing['id'] ?>/resolve"
                                        data-reload="true"
                                        data-success="Thanks — the worker will re-verify and continue.">
                                    <?= Ui::icon('check', 15) ?> I finished the Facebook check — resume
                                </button>
                            <?php else: ?>
                                <button class="btn btn--primary btn--sm"
                                        data-action="/queue/<?= (int) $viewing['id'] ?>/retry"
                                        data-reload="true" data-success="Job requeued.">
                                    <?= Ui::icon('refresh', 15) ?> Retry
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if (in_array((string) $viewing['status'], array_merge(\App\Models\Job::PENDING, ['PAUSED']), true)): ?>
                            <button class="btn btn--sm" data-action="/queue/<?= (int) $viewing['id'] ?>/cancel"
                                    data-reload="true" data-success="Job cancelled.">
                                <?= Ui::icon('x', 15) ?> Cancel
                            </button>
                        <?php endif; ?>
                        <?php if (!empty($viewing['screenshot_path'])): ?>
                            <a class="btn btn--sm" href="/queue/<?= (int) $viewing['id'] ?>/screenshot" target="_blank" rel="noopener">
                                <?= Ui::icon('eye', 15) ?> Failure screenshot
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div>
                    <h3 class="small">State history</h3>
                    <ul class="timeline">
                        <?php foreach ($viewing['events'] as $event): ?>
                            <?php
                            $tone = match ((string) $event['to_state']) {
                                'PUBLISHED' => 'is-ok',
                                'FAILED', 'CANCELLED' => 'is-bad',
                                'USER_ACTION_REQUIRED', 'ACCOUNT_REAUTH_REQUIRED', 'RETRYING' => 'is-warn',
                                default => 'is-active',
                            };
                            ?>
                            <li class="<?= $tone ?>">
                                <strong><?= Support::e(Ui::humanize((string) $event['to_state'])) ?></strong>
                                <?php if (!empty($event['message'])): ?>
                                    <p class="small muted mb-0"><?= Support::e(Support::excerpt((string) $event['message'], 140)) ?></p>
                                <?php endif; ?>
                                <span class="soft tiny"><?= Support::e(Ui::localTime((string) $event['created_at'], $timezone, 'H:i:s')) ?>
                                    · <?= Support::e((string) $event['actor']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </section>
<?php endif; ?>

<form class="card mb-2" method="get" action="/queue">
    <div class="card__body">
        <div class="form-row">
            <div class="field">
                <label class="field__label" for="status">Status</label>
                <select id="status" name="status">
                    <option value="">All jobs</option>
                    <option value="PENDING"<?= $filters['status'] === 'PENDING' ? ' selected' : '' ?>>Pending (scheduled / queued / retrying)</option>
                    <option value="ACTIVE"<?= $filters['status'] === 'ACTIVE' ? ' selected' : '' ?>>In flight</option>
                    <option value="ACTION"<?= $filters['status'] === 'ACTION' ? ' selected' : '' ?>>Needs a human</option>
                    <?php foreach ($statuses as $status): ?>
                        <option value="<?= Support::e($status) ?>"<?= $filters['status'] === $status ? ' selected' : '' ?>>
                            <?= Support::e(Ui::humanize($status)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="field__label" for="worker">Worker</label>
                <select id="worker" name="worker">
                    <option value="0">Any worker</option>
                    <?php foreach ($workers as $worker): ?>
                        <option value="<?= (int) $worker['id'] ?>"<?= (int) $filters['worker_id'] === (int) $worker['id'] ? ' selected' : '' ?>>
                            <?= Support::e((string) $worker['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <button class="btn btn--primary" type="submit"><?= Ui::icon('search', 15) ?> Filter</button>
    </div>
</form>

<form method="post" action="/queue/bulk" id="bulk-form">
    <input type="hidden" name="_csrf" value="<?= Support::e($csrfToken) ?>">
    <section class="card">
        <div class="card__head">
            <h2><?= count($jobs) ?> job(s)</h2>
            <span class="spacer"></span>
            <div class="row">
                <button class="btn btn--sm" type="submit" name="action" value="retry"><?= Ui::icon('refresh', 15) ?> Retry selected</button>
                <button class="btn btn--sm" type="submit" name="action" value="cancel"><?= Ui::icon('x', 15) ?> Cancel selected</button>
                <span data-poll="15000" data-poll-source="/api/status"></span>
            </div>
        </div>
        <div class="card__body card__body--flush">
            <?php if ($jobs === []): ?>
                <?= Ui::emptyState('No jobs match this filter', 'Jobs appear as soon as you publish or schedule a post.', 'queue', '/posts/new', 'Create a post') ?>
            <?php else: ?>
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th style="width:34px"><input type="checkbox" data-check-all="#bulk-form"></th>
                                <th>#</th><th>Page</th><th>Content</th><th>Status</th><th>Progress</th>
                                <th>Worker</th><th>When</th><th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($jobs as $job): ?>
                            <tr>
                                <td><input type="checkbox" name="job_ids[]" value="<?= (int) $job['id'] ?>"></td>
                                <td class="mono">#<?= (int) $job['id'] ?></td>
                                <td>
                                    <?= Support::e((string) $job['page_name']) ?>
                                    <span class="soft tiny"><?= Support::e((string) $job['account_label']) ?></span>
                                </td>
                                <td>
                                    <span class="truncate small">
                                        <?= Support::e(Support::excerpt((string) ($job['caption'] ?? ''), 50) ?: '(media only)') ?>
                                    </span>
                                    <span class="soft tiny"><?= Support::e(Ui::humanize((string) $job['job_type'])) ?></span>
                                </td>
                                <td>
                                    <?= Ui::status((string) $job['status']) ?>
                                    <?php if (!empty($job['error_code'])): ?>
                                        <span class="pill pill--bad tiny"><?= Support::e(Ui::humanize((string) $job['error_code'])) ?></span>
                                    <?php endif; ?>
                                    <?php if ((int) $job['attempts'] > 1): ?>
                                        <span class="soft tiny">attempt <?= (int) $job['attempts'] ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="min-width:110px">
                                    <?= Ui::progress((int) $job['progress_pct'], (string) $job['status'] === 'PUBLISHED' ? 'ok' : ((string) $job['status'] === 'FAILED' ? 'bad' : 'active')) ?>
                                    <span class="tiny muted"><?= Support::e(Ui::humanize((string) ($job['stage'] ?? ''))) ?></span>
                                </td>
                                <td class="small muted"><?= Support::e((string) ($job['worker_name'] ?? '—')) ?></td>
                                <td class="small nowrap">
                                    <?= Support::e(Ui::localTime((string) $job['scheduled_at'], $timezone, 'M j, H:i')) ?>
                                    <span class="soft tiny"><?= Support::e(Ui::ago((string) $job['updated_at'])) ?></span>
                                </td>
                                <td class="nowrap">
                                    <a class="btn btn--sm" href="/queue?job=<?= (int) $job['id'] ?>">Open</a>
                                    <?php if (!empty($job['result_url'])): ?>
                                        <a class="btn btn--sm" href="<?= Support::e((string) $job['result_url']) ?>" target="_blank" rel="noopener noreferrer"><?= Ui::icon('external', 14) ?></a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>
</form>
