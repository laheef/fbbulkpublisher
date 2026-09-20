<?php
declare(strict_types=1);
use App\Core\Support;
use App\Core\Ui;

/** @var list<array> $workerErrors @var list<array> $failedJobs @var list<array> $categories */
?>
<div class="page-head">
    <div class="page-head__text">
        <h1>Errors</h1>
        <p class="page-head__sub">Machine-reported problems and jobs that need review.</p>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="/admin/diagnostics"><?= Ui::icon('download', 16) ?> Export diagnostics</a>
    </div>
</div>

<nav class="tabs">
    <a href="/admin">Overview</a>
    <a href="/admin/users">Users</a>
    <a href="/admin/workers">Fleet</a>
    <a href="/admin/activity">Activity</a>
    <a class="is-active" href="/admin/errors">Errors</a>
</nav>

<div class="grid grid--2">
    <section class="card">
        <div class="card__head"><h2>Worker errors and warnings</h2></div>
        <div class="card__body card__body--flush">
            <?php if ($workerErrors === []): ?>
                <?= Ui::emptyState('No worker errors', 'Machines report problems here as they happen.', 'check') ?>
            <?php else: ?>
                <div class="table-scroll">
                    <table>
                        <thead><tr><th>When</th><th>Machine</th><th>Level</th><th>Message</th></tr></thead>
                        <tbody>
                        <?php foreach ($workerErrors as $error): ?>
                            <tr>
                                <td class="small muted nowrap"><?= Support::e(Ui::ago((string) $error['created_at'])) ?></td>
                                <td class="small">
                                    <?= Support::e((string) ($error['worker_name'] ?? 'unknown')) ?>
                                    <span class="soft tiny"><?= Support::e((string) ($error['user_email'] ?? '')) ?></span>
                                </td>
                                <td><span class="pill pill--<?= $error['level'] === 'error' ? 'bad' : 'warn' ?>"><?= Support::e((string) $error['level']) ?></span></td>
                                <td class="small"><?= Support::e(Support::truncate((string) $error['message'], 110)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="card">
        <div class="card__head"><h2>Jobs needing review</h2></div>
        <div class="card__body card__body--flush">
            <?php if ($failedJobs === []): ?>
                <?= Ui::emptyState('Nothing failed', 'Failed and action-required jobs appear here.', 'check') ?>
            <?php else: ?>
                <div class="table-scroll">
                    <table>
                        <thead><tr><th>#</th><th>Page</th><th>Code</th><th>Workspace</th><th>Updated</th></tr></thead>
                        <tbody>
                        <?php foreach ($failedJobs as $job): ?>
                            <tr>
                                <td class="mono">#<?= (int) $job['id'] ?></td>
                                <td class="small"><?= Support::e(Support::truncate((string) $job['page_name'], 24)) ?></td>
                                <td>
                                    <?= Ui::status((string) $job['status']) ?>
                                    <span class="tiny mono"><?= Support::e((string) ($job['error_code'] ?? '')) ?></span>
                                </td>
                                <td class="tiny muted"><?= Support::e((string) $job['user_email']) ?></td>
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

<?php if ($categories !== []): ?>
    <section class="card mt-2">
        <div class="card__head"><h2>Failure taxonomy (30 days)</h2></div>
        <div class="card__body card__body--flush">
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Code</th><th>Meaning</th><th>Handling</th><th class="num">Occurrences</th></tr></thead>
                    <tbody>
                    <?php foreach ($categories as $category): ?>
                        <tr>
                            <td class="mono small"><?= Support::e((string) $category['code']) ?></td>
                            <td class="small"><?= Support::e((string) $category['label']) ?></td>
                            <td>
                                <?php if ($category['retryable']): ?>
                                    <span class="pill pill--info">retried automatically</span>
                                <?php else: ?>
                                    <span class="pill pill--warn">awaits a human</span>
                                <?php endif; ?>
                            </td>
                            <td class="num"><?= (int) $category['occurrences'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
<?php endif; ?>
