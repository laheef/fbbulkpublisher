<?php
declare(strict_types=1);
use App\Core\Support;
use App\Core\Ui;

/** @var list<array> $entries @var array $filters */
?>
<div class="page-head">
    <div class="page-head__text">
        <h1>Activity log</h1>
        <p class="page-head__sub">Every operator and worker action, newest first.</p>
    </div>
</div>

<nav class="tabs">
    <a href="/admin">Overview</a>
    <a href="/admin/users">Users</a>
    <a href="/admin/workers">Fleet</a>
    <a class="is-active" href="/admin/activity">Activity</a>
    <a href="/admin/errors">Errors</a>
</nav>

<form class="card mb-2" method="get" action="/admin/activity">
    <div class="card__body">
        <div class="field">
            <label class="field__label" for="action">Filter by action</label>
            <input id="action" name="action" type="text" value="<?= Support::e((string) $filters['action']) ?>" placeholder="e.g. job, account, worker">
        </div>
        <button class="btn btn--primary" type="submit"><?= Ui::icon('search', 15) ?> Filter</button>
    </div>
</form>

<section class="card">
    <div class="card__body card__body--flush">
        <?php if ($entries === []): ?>
            <?= Ui::emptyState('No activity recorded', '', 'posts') ?>
        <?php else: ?>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Entity</th><th>Meta</th></tr></thead>
                    <tbody>
                    <?php foreach ($entries as $entry): ?>
                        <tr>
                            <td class="small muted nowrap"><?= Support::e(Ui::ago((string) $entry['created_at'])) ?></td>
                            <td class="small">
                                <?= Support::e((string) ($entry['user_name'] ?? $entry['user_email'] ?? 'system')) ?>
                                <span class="soft tiny"><?= Support::e((string) $entry['actor_type']) ?></span>
                            </td>
                            <td class="mono tiny"><?= Support::e((string) $entry['action']) ?></td>
                            <td class="small muted">
                                <?php if (!empty($entry['entity_type'])): ?>
                                    <?= Support::e((string) $entry['entity_type']) ?>#<?= (int) ($entry['entity_id'] ?? 0) ?>
                                <?php else: ?><span class="soft">—</span><?php endif; ?>
                            </td>
                            <td class="tiny mono" style="max-width:340px;word-break:break-word">
                                <?= Support::e(Support::truncate((string) ($entry['meta'] ?? ''), 90)) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
