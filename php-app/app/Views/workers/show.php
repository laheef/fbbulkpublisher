<?php
declare(strict_types=1);
use App\Core\Support;
use App\Core\Ui;

/** @var array $worker @var list<array> $logs @var list<array> $jobs @var array $stats @var list<array> $accounts @var array $capabilities */
?>
<div class="page-head">
    <div class="page-head__text">
        <h1><?= Support::e((string) $worker['name']) ?> <?= Ui::status((string) $worker['status']) ?></h1>
        <p class="page-head__sub">
            <span class="mono small"><?= Support::e((string) $worker['worker_id']) ?></span> ·
            <?= Support::e((string) ($worker['os'] ?? 'unknown OS')) ?> ·
            <?= Support::e((string) ($worker['arch'] ?? '')) ?>
        </p>
    </div>
    <div class="page-head__actions">
        <?php if ((string) $worker['status'] === 'PAUSED'): ?>
            <button class="btn btn--primary" data-action="/workers/<?= (int) $worker['id'] ?>/resume" data-reload="true" data-success="Worker resumed.">
                <?= Ui::icon('play', 16) ?> Resume automation
            </button>
        <?php else: ?>
            <button class="btn" data-action="/workers/<?= (int) $worker['id'] ?>/pause" data-reload="true" data-success="Worker paused. It will finish the job in hand and stop claiming.">
                <?= Ui::icon('pause', 16) ?> Pause automation
            </button>
        <?php endif; ?>
        <a class="btn" href="/workers"><?= Ui::icon('x', 16) ?> All workers</a>
    </div>
</div>

<div class="grid grid--sidebar">
    <div class="stack">
        <section class="card">
            <div class="card__head"><h2>Health</h2></div>
            <div class="card__body">
                <div class="grid grid--3">
                    <div class="stat"><span class="stat__label">CPU</span>
                        <span class="stat__value"><?= $worker['cpu_pct'] !== null ? Support::e(rtrim(rtrim(number_format((float) $worker['cpu_pct'], 1), '0'), '.')) . '%' : '—' ?></span></div>
                    <div class="stat"><span class="stat__label">Memory</span>
                        <span class="stat__value"><?= $worker['mem_mb'] !== null ? (int) $worker['mem_mb'] : '—' ?><span class="small muted"> MB</span></span></div>
                    <div class="stat"><span class="stat__label">Jobs held</span>
                        <span class="stat__value"><?= (int) $worker['pending_count'] ?></span></div>
                </div>

                <hr class="hr">
                <dl class="kv">
                    <dt>Last heartbeat</dt><dd><?= Support::e(Ui::ago((string) ($worker['last_heartbeat_at'] ?? ''))) ?></dd>
                    <dt>Connected since</dt><dd><?= Support::e(Ui::ago((string) ($worker['connected_at'] ?? ''))) ?></dd>
                    <dt>Internet</dt>
                    <dd><?= (int) $worker['internet_ok'] === 1 ? '<span class="pill pill--ok">reachable</span>' : '<span class="pill pill--bad">offline</span>' ?></dd>
                    <dt>Token</dt>
                    <dd class="mono small"><?= Support::e((string) $worker['token_prefix']) ?>… · rotated <?= Support::e(Ui::ago((string) ($worker['token_rotated_at'] ?? ''))) ?></dd>
                </dl>
            </div>
        </section>

        <section class="card">
            <div class="card__head">
                <h2>Mirrored logs</h2>
                <span class="spacer"></span>
                <span class="tiny muted"><?= (int) $stats['total'] ?> lines · <?= (int) $stats['errors'] ?> errors · <?= (int) $stats['warnings'] ?> warnings</span>
            </div>
            <div class="card__body">
                <?php if ($logs === []): ?>
                    <p class="muted small mb-0">No log lines have been shipped from this machine yet.</p>
                <?php else: ?>
                    <div class="log-view">
                        <?php foreach ($logs as $log): ?>
                            <div class="log-view__line">
                                <span class="log-view__ts"><?= Support::e((string) $log['created_at']) ?></span>
                                <span class="log-view__level log-view__level--<?= Support::e((string) $log['level']) ?>"><?= Support::e((string) $log['level']) ?></span>
                                <span class="log-view__channel"><?= Support::e((string) $log['channel']) ?></span>
                                <span><?= Support::e((string) $log['message']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="tiny muted mt-1">
                        Log lines are redacted on arrival: cookies, tokens and credentials never reach this server.
                    </p>
                <?php endif; ?>
            </div>
        </section>

        <section class="card">
            <div class="card__head"><h2>Jobs handled</h2></div>
            <div class="card__body card__body--flush">
                <?php if ($jobs === []): ?>
                    <?= Ui::emptyState('This machine has not run any jobs yet', '', 'queue') ?>
                <?php else: ?>
                    <div class="table-scroll">
                        <table>
                            <thead><tr><th>#</th><th>Page</th><th>Status</th><th>Attempts</th><th>Updated</th></tr></thead>
                            <tbody>
                            <?php foreach ($jobs as $job): ?>
                                <tr>
                                    <td class="mono">#<?= (int) $job['id'] ?></td>
                                    <td class="small"><?= Support::e((string) $job['page_name']) ?></td>
                                    <td><?= Ui::status((string) $job['status']) ?></td>
                                    <td class="small"><?= (int) $job['attempts'] ?>/<?= (int) $job['max_attempts'] ?></td>
                                    <td class="small muted"><?= Support::e(Ui::ago((string) $job['updated_at'])) ?></td>
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
            <div class="card__head"><h2>Runtime versions</h2></div>
            <div class="card__body">
                <dl class="kv">
                    <dt>App</dt><dd><?= Support::e((string) ($worker['app_version'] ?? '—')) ?></dd>
                    <dt>Worker</dt><dd><?= Support::e((string) ($worker['worker_version'] ?? '—')) ?></dd>
                    <dt>Playwright</dt><dd><?= Support::e((string) ($worker['playwright_version'] ?? '—')) ?></dd>
                    <dt>Chromium</dt><dd><?= Support::e((string) ($worker['browser_version'] ?? '—')) ?></dd>
                    <dt>FFmpeg</dt><dd><?= Support::e((string) ($worker['ffmpeg_version'] ?? '—')) ?></dd>
                </dl>

                <?php if ($capabilities !== []): ?>
                    <hr class="hr">
                    <dl class="kv">
                        <?php foreach ($capabilities as $key => $value): ?>
                            <dt><?= Support::e(Ui::humanize((string) $key)) ?></dt>
                            <dd class="small"><?= Support::e(is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value) ?></dd>
                        <?php endforeach; ?>
                    </dl>
                <?php endif; ?>
            </div>
        </section>

        <section class="card">
            <div class="card__head"><h2>Machine limits</h2></div>
            <div class="card__body">
                <form method="post" action="/workers/<?= (int) $worker['id'] ?>/settings">
                    <input type="hidden" name="_csrf" value="<?= Support::e($csrfToken) ?>">
                    <div class="field">
                        <label class="field__label" for="max_concurrent_jobs">Concurrent jobs</label>
                        <input id="max_concurrent_jobs" name="max_concurrent_jobs" type="number" min="1" max="32"
                               value="<?= (int) $worker['max_concurrent_jobs'] ?>">
                        <p class="field__hint">How many jobs this machine may run at once.</p>
                    </div>
                    <div class="field">
                        <label class="field__label" for="max_concurrent_browsers">Concurrent browsers</label>
                        <input id="max_concurrent_browsers" name="max_concurrent_browsers" type="number" min="1" max="16"
                               value="<?= (int) $worker['max_concurrent_browsers'] ?>">
                        <p class="field__hint">Keep this at or below the job count; each browser needs roughly 300–500 MB of RAM.</p>
                    </div>
                    <div class="field">
                        <label class="field__label" for="idle_browser_timeout_s">Close idle browsers after (seconds)</label>
                        <input id="idle_browser_timeout_s" name="idle_browser_timeout_s" type="number" min="30" max="7200" value="300">
                    </div>
                    <div class="field">
                        <label class="field__label" for="trace_mode">Playwright traces</label>
                        <select id="trace_mode" name="trace_mode">
                            <option value="off">Off</option>
                            <option value="failures_only" selected>Failures only (recommended)</option>
                            <option value="all">All runs (developer use)</option>
                        </select>
                    </div>
                    <button class="btn btn--primary btn--block" type="submit">Save machine limits</button>
                </form>
            </div>
        </section>

        <section class="card">
            <div class="card__head"><h2>Danger zone</h2></div>
            <div class="card__body">
                <button class="btn btn--block"
                        data-action="/workers/<?= (int) $worker['id'] ?>/rotate"
                        data-confirm="Rotate this machine's token? The app on that PC must be given the new token before it can reconnect."
                        data-success="Token rotated — copy it into the app on that PC.">
                    <?= Ui::icon('shield', 16) ?> Rotate worker token
                </button>

                <p class="tiny muted mt-1">
                    New token: <span class="mono" id="rotated-token">—</span>
                </p>

                <button class="btn btn--danger btn--block mt-2"
                        data-action="/workers/<?= (int) $worker['id'] ?>/remove"
                        data-confirm="Detach this machine? Its accounts become disconnected and queued jobs return to the pool."
                        data-reload="true"
                        data-success="Machine detached.">
                    <?= Ui::icon('trash', 16) ?> Remove machine
                </button>
            </div>
        </section>
    </div>
</div>
