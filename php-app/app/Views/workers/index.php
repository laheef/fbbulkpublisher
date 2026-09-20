<?php
declare(strict_types=1);
use App\Core\Support;
use App\Core\Ui;

/** @var list<array> $workers @var array $summary @var int $offline_after */
?>
<div class="page-head">
    <div class="page-head__text">
        <h1>Workers</h1>
        <p class="page-head__sub">
            Each Windows installation registers itself here. A worker is considered offline after
            <?= (int) $offline_after ?> seconds without a heartbeat, and its leases are released safely.
        </p>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="/settings"><?= Ui::icon('settings', 16) ?> Default concurrency</a>
    </div>
</div>

<div class="grid grid--4 mb-2">
    <div class="card"><div class="card__body"><div class="stat">
        <span class="stat__label">Machines</span><span class="stat__value"><?= (int) $summary['total'] ?></span>
    </div></div></div>
    <div class="card"><div class="card__body"><div class="stat stat--ok">
        <span class="stat__label">Online</span><span class="stat__value"><?= (int) $summary['online'] ?></span>
    </div></div></div>
    <div class="card"><div class="card__body"><div class="stat stat--brand">
        <span class="stat__label">Job capacity</span><span class="stat__value"><?= (int) $summary['capacity'] ?></span>
    </div></div></div>
    <div class="card"><div class="card__body"><div class="stat stat--warn">
        <span class="stat__label">Jobs held</span><span class="stat__value"><?= (int) $summary['pending_jobs'] ?></span>
    </div></div></div>
</div>

<?php if ($workers === []): ?>
    <div class="card">
        <?= Ui::emptyState(
            'No Windows machine connected yet',
            'Install LinkEasy Facebook Publisher Setup.exe on the PC that will do the publishing, launch it, and sign in to this workspace. The machine appears here within seconds.',
            'workers'
        ) ?>
    </div>
<?php else: ?>
    <section class="card">
        <div class="card__body card__body--flush">
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Machine</th><th>Status</th><th>Versions</th><th class="num">CPU</th><th class="num">RAM</th>
                            <th>Current job</th><th>Last heartbeat</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($workers as $worker): ?>
                        <tr>
                            <td>
                                <strong><?= Support::e((string) $worker['name']) ?></strong>
                                <span class="soft tiny mono"><?= Support::e(substr((string) $worker['worker_id'], 0, 8)) ?>…</span>
                            </td>
                            <td>
                                <?= Ui::status((string) $worker['status']) ?>
                                <?php if ((int) $worker['is_enabled'] !== 1): ?><span class="pill pill--muted tiny">disabled</span><?php endif; ?>
                                <?php if (!$worker['is_online']): ?><span class="pill pill--bad tiny">stale</span><?php endif; ?>
                            </td>
                            <td class="tiny muted">
                                app <?= Support::e((string) ($worker['app_version'] ?? '—')) ?><br>
                                playwright <?= Support::e((string) ($worker['playwright_version'] ?? '—')) ?><br>
                                chromium <?= Support::e(Support::truncate((string) ($worker['browser_version'] ?? '—'), 26)) ?>
                            </td>
                            <td class="num small"><?= $worker['cpu_pct'] !== null ? Support::e(rtrim(rtrim(number_format((float) $worker['cpu_pct'], 1), '0'), '.')) . '%' : '—' ?></td>
                            <td class="num small"><?= $worker['mem_mb'] !== null ? (int) $worker['mem_mb'] . ' MB' : '—' ?></td>
                            <td class="small">
                                <?php if (!empty($worker['current_job_id'])): ?>
                                    <a href="/queue?job=<?= (int) $worker['current_job_id'] ?>">#<?= (int) $worker['current_job_id'] ?></a>
                                    <?= Ui::status((string) ($worker['current_job_status'] ?? '')) ?>
                                <?php else: ?><span class="soft">idle</span><?php endif; ?>
                            </td>
                            <td class="small nowrap">
                                <?= Support::e(Ui::ago((string) ($worker['last_heartbeat_at'] ?? ''))) ?>
                                <?php if ($worker['heartbeat_age'] !== null): ?>
                                    <span class="soft tiny"><?= (int) $worker['heartbeat_age'] ?>s</span>
                                <?php endif; ?>
                            </td>
                            <td class="nowrap">
                                <a class="btn btn--sm" href="/workers/<?= (int) $worker['id'] ?>">Open</a>
                                <?php if ((string) $worker['status'] === 'PAUSED'): ?>
                                    <button class="btn btn--sm" data-action="/workers/<?= (int) $worker['id'] ?>/resume" data-reload="true" data-success="Worker resumed."><?= Ui::icon('play', 14) ?></button>
                                <?php else: ?>
                                    <button class="btn btn--sm" data-action="/workers/<?= (int) $worker['id'] ?>/pause" data-reload="true" data-success="Worker paused."><?= Ui::icon('pause', 14) ?></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
<?php endif; ?>

<section class="card mt-2">
    <div class="card__head"><h2>How to connect a machine</h2></div>
    <div class="card__body">
        <ol class="small" style="margin:0;padding-left:18px;line-height:1.8">
            <li>Download <strong>LinkEasy Facebook Publisher Setup.exe</strong> from your workspace.</li>
            <li>Run it on a Windows 10/11 64-bit PC. The installer sets up its own Node runtime, Playwright, Chromium and FFmpeg — nothing else is needed.</li>
            <li>Launch the app and sign in with your LinkEasy workspace email and password.</li>
            <li>The app registers itself, stores its worker token with Windows DPAPI, and starts heart-beating.</li>
            <li>Click <em>Connect Facebook Account</em> in the app, sign in to Facebook in the window that opens, and pick your Pages.</li>
        </ol>
        <p class="small muted mb-0 mt-2">
            Nothing is installed system-wide: the runtime lives inside the application folder, and each Windows user
            keeps separate profiles, credentials, logs and settings.
        </p>
    </div>
</section>
