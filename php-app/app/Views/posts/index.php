<?php
declare(strict_types=1);
use App\Core\Support;
use App\Core\Ui;

/** @var list<array> $posts @var array $filters @var array $summary @var list<string> $statuses @var list<string> $kinds */
?>
<div class="page-head">
    <div class="page-head__text">
        <h1>Posts</h1>
        <p class="page-head__sub">
            <?= (int) $summary['published'] ?> published · <?= (int) $summary['scheduled'] + (int) $summary['drafts'] ?> pending ·
            <?= (int) $summary['failed'] ?> failed · <?= (int) $summary['partial'] ?> partially published
        </p>
    </div>
    <div class="page-head__actions">
        <a class="btn btn--primary" href="/posts/new"><?= Ui::icon('plus', 16) ?> New post</a>
    </div>
</div>

<form class="card mb-2" method="get" action="/posts">
    <div class="card__body">
        <div class="form-row">
            <div class="field">
                <label class="field__label" for="q">Search caption</label>
                <input id="q" name="q" type="text" value="<?= Support::e((string) $filters['search']) ?>">
            </div>
            <div class="field">
                <label class="field__label" for="status">Status</label>
                <select id="status" name="status">
                    <option value="">Any status</option>
                    <?php foreach ($statuses as $status): ?>
                        <option value="<?= Support::e($status) ?>"<?= $filters['status'] === $status ? ' selected' : '' ?>>
                            <?= Support::e(Ui::humanize($status)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="field__label" for="kind">Type</label>
                <select id="kind" name="kind">
                    <option value="">Any type</option>
                    <?php foreach ($kinds as $kind): ?>
                        <option value="<?= Support::e($kind) ?>"<?= $filters['kind'] === $kind ? ' selected' : '' ?>>
                            <?= Support::e(Ui::humanize($kind)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="row">
            <button class="btn btn--primary" type="submit"><?= Ui::icon('search', 15) ?> Filter</button>
            <a class="btn" href="/posts">Reset</a>
        </div>
    </div>
</form>

<section class="card">
    <div class="card__body card__body--flush">
        <?php if ($posts === []): ?>
            <?= Ui::emptyState('No posts yet', 'Compose a post, choose your Pages, then publish now or schedule it.', 'posts', '/posts/new', 'Create a post') ?>
        <?php else: ?>
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Content</th><th>Type</th><th>Status</th><th class="num">Pages</th>
                            <th>Result</th><th>Created</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($posts as $post): ?>
                        <tr>
                            <td>
                                <a href="/posts/<?= (int) $post['id'] ?>">
                                    <?= Support::e(Support::excerpt((string) ($post['caption'] ?? ''), 70) ?: '(no caption)') ?>
                                </a>
                                <?php if (!empty($post['hashtags'])): ?>
                                    <span class="soft tiny"><?= Support::e(Support::excerpt((string) $post['hashtags'], 40)) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= Support::e(Ui::humanize((string) $post['kind'])) ?>
                                <?php if (!empty($post['media_kind'])): ?>
                                    <span class="soft tiny">with <?= Support::e(strtolower((string) $post['media_kind'])) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= Ui::status((string) $post['status']) ?></td>
                            <td class="num">
                                <?= (int) $post['published_count'] ?>/<?= (int) $post['page_count'] ?>
                                <?php if ((int) $post['failed_count'] > 0): ?>
                                    <span class="pill pill--bad tiny"><?= (int) $post['failed_count'] ?> failed</span>
                                <?php endif; ?>
                            </td>
                            <td class="small">
                                <?php if ($post['status'] === 'SCHEDULED' && !empty($post['scheduled_at'])): ?>
                                    <?= Support::e(Ui::localTime((string) $post['scheduled_at'], $timezone, 'M j, H:i')) ?>
                                <?php elseif (!empty($post['published_at'])): ?>
                                    <?= Support::e(Ui::ago((string) $post['published_at'])) ?>
                                <?php else: ?>
                                    <span class="soft">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="small muted nowrap"><?= Support::e(Ui::ago((string) $post['created_at'])) ?></td>
                            <td class="nowrap">
                                <a class="btn btn--sm" href="/posts/<?= (int) $post['id'] ?>">Open</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
