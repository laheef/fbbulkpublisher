<?php
declare(strict_types=1);
use App\Core\Support;
use App\Core\Ui;

/** @var array $overview @var list<array> $workers @var array $queue @var array $config @var array $storage */
?>
<div class="page-head">
    <div class="page-head__text">
        <h1>Administration</h1>
        <p class="page-head__sub">Platform-wide health across every workspace on this installation.</p>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="/admin/errors"><?= Ui::icon('alert', 16) ?> Errors</a>
        <a class="btn" href="/admin/diagnostics"><?= Ui::icon('download', 16) ?> Export diagnostics</a>
    </div>
</div>

<nav class="tabs">
    <a class="is-active" href="/admin">Overview</a>
    <a href="/admin/users">Users</a>
    <a href="/admin/workers">Fleet</a>
    <a href="/admin/activity">Activity</a>
    <a href="/admin/errors">Errors</a>
</nav>

<div class="grid grid--4 mb-2">
    <div class="card"><div class="card__body"><div class="stat">
        <span class="stat__label">Workspaces</span><span class="stat__value"><?= (int) $overview['users'] ?></span>
    </div></div></div>
    <div class="card"><div class="card__body"><div class="stat stat--ok">
        <span class="stat__label">Workers online</span><span class="stat__value"><?= (int) $overview['workers_online'] ?></span>
        <span class="stat__meta"><?= (int) $overview['workers'] ?> registered</span>
    </div></div></div>
    <div class="card"><div class="card__body"><div class="stat stat--brand">
        <span class="stat__label">Queue depth</span><span class="stat__value"><?= (int) $overview['queue_depth'] ?></span>
        <span class="stat__meta">platform-wide pending jobs</span>
    </div></div></div>
    <div class="card"><div class="card__body"><div class="stat stat--bad">
        <span class="stat__label">Errors (24 h)</span><span class="stat__value"><?= (int) $overview['errors_24h'] ?></span>
    </div></div></div>
</div>

<div class="grid grid--2 mb-2">
    <section class="card">
        <div class="card__head"><h2>Queue health</h2></div>
        <div class="card__body">
            <dl class="kv">
                <dt>Queued + scheduled</dt><dd><?= (int) $queue['depth'] ?></dd>
                <dt>In flight</dt><dd><?= (int) $queue['in_flight'] ?></dd>
                <dt>Expired leases</dt>
                <dd>
                    <?= (int) $queue['stuck'] ?>
                    <?php if ((int) $queue['stuck'] > 0): ?>
                        <span class="pill pill--warn">needs a sweep</span>
                    <?php endif; ?>
                </dd>
                <dt>Waiting on a human</dt><dd><?= (int) $queue['action_required'] ?></dd>
                <dt>Oldest queued</dt><dd><?= Support::e($queue['oldest_queued'] === null ? '—' : Ui::ago((string) $queue['oldest_queued'])) ?></dd>
                <dt>Failed in 24 h</dt><dd><?= (int) $queue['failed_24h'] ?></dd>
            </dl>

            <hr class="hr">
            <div class="row">
                <button class="btn btn--sm" data-action="/admin/maintenance" data-method="POST"
                        onclick="this.form && this.form.submit && this.form.submit();"
                        data-success="Maintenance queued."><?= Ui::icon('refresh', 15) ?> Run scheduler tick</button>
            </div>
            <form method="post" action="/admin/maintenance" class="row mt-1">
                <input type="hidden" name="_csrf" value="<?= Support::e($csrfToken) ?>">
                <button class="btn btn--sm" name="action" value="sweep_leases"><?= Ui::icon('shield', 15) ?> Release expired leases</button>
                <button class="btn btn--sm" name="action" value="offline"><?= Ui::icon('pause', 15) ?> Flag silent workers offline</button>
                <button class="btn btn--sm" name="action" value="housekeeping"><?= Ui::icon('trash', 15) ?> Housekeeping</button>
                <button class="btn btn--sm" name="action" value="scheduler_tick"><?= Ui::icon('clock', 15) ?> Scheduler tick</button>
            </form>
        </div>
    </section>

    <section class="card">
        <div class="card__head"><h2>Runtime configuration</h2></div>
        <div class="card__body">
            <dl class="kv">
                <dt>Environment</dt><dd><?= Support::e((string) $config['env']) ?></dd>
                <dt>Debug mode</dt><dd><?= $config['debug'] ? '<span class="pill pill--warn">enabled</span>' : '<span class="pill pill--ok">disabled</span>' ?></dd>
                <dt>Provider</dt><dd><?= Support::e((string) $config['provider']) ?></dd>
                <dt>Database driver</dt><dd><?= Support::e((string) $config['db_driver']) ?></dd>
                <dt>PHP</dt><dd><?= Support::e((string) $config['php']) ?></dd>
                <dt>FFmpeg (server)</dt><dd><?= Support::e($config['ffmpeg'] === null ? 'not installed — the Windows app performs media probing' : Support::truncate((string) $config['ffmpeg'], 40)) ?></dd>
                <dt>Uploads directory</dt><dd class="mono tiny"><?= Support::e((string) $config['uploads_dir']) ?></dd>
            </dl>
        </div>
    </section>
</div>

<div class="grid grid--2">
    <section class="card">
        <div class="card__head"><h2>Storage</h2></div>
        <div class="card__body">
            <dl class="kv">
                <dt>Media files</dt><dd><?= (int) $storage['media_files'] ?> · <?= Support::e(Support::bytesToHuman((int) $storage['media_bytes'])) ?></dd>
                <dt>Awaiting cleanup</dt><dd><?= (int) $storage['soft_deleted'] ?></dd>
                <dt>Worker log rows</dt><dd><?= (int) $storage['worker_logs'] ?></dd>
                <dt>Activity rows</dt><dd><?= (int) $storage['activity_logs'] ?></dd>
            </dl>
            <p class="tiny muted mb-0 mt-1">
                Deleted media is only removed from disk once no live job or draft references it.
            </p>
        </div>
    </section>

    <section class="card">
        <div class="card__head"><h2>Fleet</h2></div>
        <div class="card__body card__body--flush">
            <?php if ($workers === []): ?>
                <?= Ui::emptyState('No machines registered', '', 'workers') ?>
            <?php else: ?>
                <div class="table-scroll">
                    <table>
                        <thead><tr><th>Machine</th><th>Workspace</th><th>Status</th><th>Heartbeat</th></tr></thead>
                        <tbody>
                        <?php foreach (array_slice($workers, 0, 8) as $worker): ?>
                            <tr>
                                <td class="small">
                                    <a href="/admin/workers"><?= Support::e((string) $worker['name']) ?></a>
                                    <span class="soft tiny">app <?= Support::e((string) ($worker['app_version'] ?? '—')) ?></span>
                                </td>
                                <td class="small muted"><?= Support::e(Support::truncate((string) ($worker['user_email'] ?? ''), 24)) ?></td>
                                <td><?= Ui::status((string) $worker['status']) ?></td>
                                <td class="small muted nowrap"><?= Support::e(Ui::ago((string) ($worker['last_heartbeat_at'] ?? ''))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>
