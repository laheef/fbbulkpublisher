<?php
declare(strict_types=1);
use App\Core\Support;
use App\Core\Ui;

/** @var array $post @var list<array> $schedules @var list<array> $events */
?>
<div class="page-head">
    <div class="page-head__text">
        <h1>Post #<?= (int) $post['id'] ?> <?= Ui::status((string) $post['status']) ?></h1>
        <p class="page-head__sub">
            <?= Support::e(Ui::humanize((string) $post['kind'])) ?> ·
            <?= (int) $post['page_count'] ?> target Pages ·
            created <?= Support::e(Ui::ago((string) $post['created_at'])) ?>
            <?php if (!empty($post['scheduled_at'])): ?>
                · scheduled <?= Support::e(Ui::localTime((string) $post['scheduled_at'], $timezone, 'M j, Y H:i')) ?>
            <?php endif; ?>
        </p>
    </div>
    <div class="page-head__actions">
        <?php if (in_array((string) $post['status'], ['FAILED', 'PARTIAL', 'CANCELLED', 'USER_ACTION_REQUIRED'], true)): ?>
            <a class="btn btn--primary" href="/posts/new?kind=<?= Support::e((string) $post['kind']) ?>"><?= Ui::icon('refresh', 16) ?> Recreate</a>
        <?php endif; ?>
        <a class="btn" href="/posts"><?= Ui::icon('x', 16) ?> All posts</a>
    </div>
</div>

<div class="grid grid--sidebar">
    <div class="stack">
        <section class="card">
            <div class="card__head"><h2>Content</h2></div>
            <div class="card__body">
                <p style="white-space:pre-wrap"><?= Support::e((string) ($post['caption'] ?? '')) ?></p>
                <?php if (!empty($post['hashtags'])): ?>
                    <p class="muted small"><?= Support::e((string) $post['hashtags']) ?></p>
                <?php endif; ?>
                <?php if (!empty($post['link_url'])): ?>
                    <p class="small"><?= Ui::icon('link', 14) ?>
                        <a href="<?= Support::e((string) $post['link_url']) ?>" target="_blank" rel="noopener noreferrer"><?= Support::e((string) $post['link_url']) ?></a>
                    </p>
                <?php endif; ?>

                <?php if (!empty($post['media_id'])): ?>
                    <hr class="hr">
                    <div class="row">
                        <?= Ui::icon($post['media_kind'] === 'VIDEO' ? 'video' : 'image', 16) ?>
                        <a href="/media/<?= (int) $post['media_id'] ?>"><?= Support::e((string) $post['original_name']) ?></a>
                        <span class="soft small">
                            <?= Support::e(Support::bytesToHuman((int) ($post['size_bytes'] ?? 0))) ?>
                            <?php if (!empty($post['media_width'])): ?>
                                · <?= (int) $post['media_width'] ?>×<?= (int) $post['media_height'] ?>
                            <?php endif; ?>
                            <?php if (!empty($post['duration_s'])): ?>
                                · <?= Support::e(Ui::duration((float) $post['duration_s'])) ?>
                            <?php endif; ?>
                            <?php if (!empty($post['aspect_ratio'])): ?>
                                · <?= Support::e((string) $post['aspect_ratio']) ?>
                            <?php endif; ?>
                        </span>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="card">
            <div class="card__head">
                <h2>Per-Page results</h2>
                <span class="spacer"></span>
                <span class="small muted">one independent job per Page</span>
            </div>
            <div class="card__body card__body--flush">
                <div class="table-scroll">
                    <table>
                        <thead><tr><th>Page</th><th>Account</th><th>Status</th><th>Result</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($post['targets'] as $target): ?>
                            <tr>
                                <td><a href="/pages/<?= (int) $target['page_id'] ?>"><?= Support::e((string) $target['page_name']) ?></a></td>
                                <td class="small muted"><?= Support::e((string) $target['account_label']) ?></td>
                                <td><?= Ui::status((string) $target['status']) ?></td>
                                <td class="small">
                                    <?php if (!empty($target['published_url'])): ?>
                                        <a href="<?= Support::e((string) $target['published_url']) ?>" target="_blank" rel="noopener noreferrer">open post</a>
                                    <?php else: ?>
                                        <span class="soft">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="nowrap">
                                    <?php if (!empty($target['job_id'])): ?>
                                        <a class="btn btn--sm" href="/queue?job=<?= (int) $target['job_id'] ?>">Job #<?= (int) $target['job_id'] ?></a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <?php if (!empty($post['jobs']) && !empty($post['jobs'][0]['id'])): ?>
            <section class="card">
                <div class="card__head">
                    <h2>Job timeline — #<?= (int) $post['jobs'][0]['id'] ?></h2>
                    <span class="spacer"></span>
                    <span class="small muted">every state change is recorded</span>
                </div>
                <div class="card__body">
                    <?php if ($events === []): ?>
                        <p class="muted small mb-0">No events recorded yet.</p>
                    <?php else: ?>
                        <ul class="timeline">
                            <?php foreach ($events as $event): ?>
                                <?php
                                $tone = match ((string) $event['to_state']) {
                                    'PUBLISHED' => 'is-ok',
                                    'FAILED', 'CANCELLED' => 'is-bad',
                                    'USER_ACTION_REQUIRED', 'ACCOUNT_REAUTH_REQUIRED', 'RETRYING' => 'is-warn',
                                    'CLAIMED', 'PROCESSING', 'UPLOADING', 'PUBLISHING', 'VERIFYING' => 'is-active',
                                    default => '',
                                };
                                ?>
                                <li class="<?= $tone ?>">
                                    <strong><?= Support::e(Ui::humanize((string) $event['to_state'])) ?></strong>
                                    <?php if (!empty($event['stage'])): ?>
                                        <span class="mono tiny soft"><?= Support::e((string) $event['stage']) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($event['message'])): ?>
                                        <p class="small muted mb-0"><?= Support::e((string) $event['message']) ?></p>
                                    <?php endif; ?>
                                    <span class="soft tiny">
                                        <?= Support::e(Ui::localTime((string) $event['created_at'], $timezone, 'M j, H:i:s')) ?>
                                        · <?= Support::e((string) $event['actor']) ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>
    </div>

    <div class="stack">
        <section class="card">
            <div class="card__head"><h2>Actions</h2></div>
            <div class="card__body">
                <button class="btn btn--block"
                        data-action="/posts/<?= (int) $post['id'] ?>/republish"
                        data-confirm="Publish this content again to its Pages? This creates new jobs, so a second post will appear on Facebook."
                        data-reload="true"
                        data-success="Re-publication queued.">
                    <?= Ui::icon('refresh', 16) ?> Publish again
                </button>

                <button class="btn btn--block mt-1"
                        data-action="/posts/<?= (int) $post['id'] ?>/cancel"
                        data-confirm="Cancel every pending job for this post?"
                        data-reload="true"
                        data-success="Pending jobs cancelled.">
                    <?= Ui::icon('pause', 16) ?> Cancel pending jobs
                </button>

                <button class="btn btn--danger btn--block mt-1"
                        data-action="/posts/<?= (int) $post['id'] ?>/remove"
                        data-confirm="Delete this post record? Published Facebook posts are not affected."
                        data-reload="true"
                        data-success="Post deleted.">
                    <?= Ui::icon('trash', 16) ?> Delete post record
                </button>
            </div>
        </section>

        <?php if ($schedules !== []): ?>
            <section class="card">
                <div class="card__head"><h2>Schedules</h2></div>
                <div class="card__body">
                    <ul class="stack" style="list-style:none;margin:0;padding:0">
                        <?php foreach ($schedules as $schedule): ?>
                            <li>
                                <?= Ui::status((string) $schedule['status']) ?>
                                <span class="small">
                                    next <?= Support::e(Ui::localTime($schedule['next_run_at'] === null ? null : (string) $schedule['next_run_at'], $timezone, 'M j, Y H:i')) ?>
                                </span>
                                <br>
                                <span class="tiny muted">
                                    <?= Support::e(Ui::humanize((string) $schedule['recurrence'])) ?>
                                    · <?= (int) $schedule['runs_count'] ?> run(s)
                                    · timezone <?= Support::e((string) $schedule['timezone']) ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </section>
        <?php endif; ?>

        <section class="card">
            <div class="card__head"><h2>Details</h2></div>
            <div class="card__body">
                <dl class="kv">
                    <dt>Status</dt><dd><?= Ui::status((string) $post['status']) ?></dd>
                    <dt>Provider</dt><dd><?= Support::e(Ui::humanize((string) $post['provider'])) ?></dd>
                    <dt>UUID</dt><dd class="mono tiny" style="word-break:break-all"><?= Support::e((string) $post['uuid']) ?></dd>
                    <dt>Created by</dt><dd><?= Support::e((string) ($user['name'] ?? '')) ?></dd>
                </dl>
            </div>
        </section>
    </div>
</div>
