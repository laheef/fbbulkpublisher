<?php
declare(strict_types=1);
use App\Core\Support;
use App\Core\Ui;

/** @var list<array> $workers @var array $summary */
?>
<div class="page-head">
    <div class="page-head__text">
        <h1>Fleet</h1>
        <p class="page-head__sub">
            <?= (int) $summary['online'] ?> of <?= (int) $summary['total'] ?> machines online ·
            <?= (int) $summary['capacity'] ?> concurrent job slots
        </p>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="/admin"><?= Ui::icon('x', 16) ?> Overview</a>
    </div>
</div>

<nav class="tabs">
    <a href="/admin">Overview</a>
    <a href="/admin/users">Users</a>
    <a class="is-active" href="/admin/workers">Fleet</a>
    <a href="/admin/activity">Activity</a>
    <a href="/admin/errors">Errors</a>
</nav>

<section class="card">
    <div class="card__body card__body--flush">
        <?php if ($workers === []): ?>
            <?= Ui::emptyState('No machines registered on this installation', '', 'workers') ?>
        <?php else: ?>
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Machine</th><th>Workspace</th><th>OS</th><th>Versions</th><th>Status</th>
                            <th>Current job</th><th class="num">CPU</th><th class="num">RAM</th><th>Heartbeat</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($workers as $worker): ?>
                        <tr>
                            <td>
                                <strong><?= Support::e((string) $worker['name']) ?></strong>
                                <span class="soft tiny mono"><?= Support::e(substr((string) $worker['worker_id'], 0, 8)) ?>…</span>
                            </td>
                            <td class="small muted"><?= Support::e((string) ($worker['user_email'] ?? '—')) ?></td>
                            <td class="tiny muted"><?= Support::e(Support::truncate((string) ($worker['os'] ?? '—'), 28)) ?></td>
                            <td class="tiny muted">
                                app <?= Support::e((string) ($worker['app_version'] ?? '—')) ?><br>
                                pw <?= Support::e((string) ($worker['playwright_version'] ?? '—')) ?><br>
                                ff <?= Support::e(Support::truncate((string) ($worker['ffmpeg_version'] ?? '—'), 22)) ?>
                            </td>
                            <td><?= Ui::status((string) $worker['status']) ?></td>
                            <td class="small">
                                <?php if (!empty($worker['current_job_id'])): ?>
                                    #<?= (int) $worker['current_job_id'] ?>
                                    <?= Ui::status((string) ($worker['current_job_status'] ?? '')) ?>
                                <?php else: ?><span class="soft">idle</span><?php endif; ?>
                            </td>
                            <td class="num small"><?= $worker['cpu_pct'] !== null ? Support::e((string) round((float) $worker['cpu_pct'], 1)) . '%' : '—' ?></td>
                            <td class="num small"><?= $worker['mem_mb'] !== null ? (int) $worker['mem_mb'] . ' MB' : '—' ?></td>
                            <td class="small muted nowrap"><?= Support::e(Ui::ago((string) ($worker['last_heartbeat_at'] ?? ''))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
