<?php
declare(strict_types=1);
use App\Core\Support;
use App\Core\Ui;

/** @var int $days @var array $overview @var list<array> $failures @var list<array> $byPage @var list<array> $byAccount @var list<array> $byWorker @var list<array> $throughput */
?>
<div class="page-head">
    <div class="page-head__text">
        <h1>Analytics</h1>
        <p class="page-head__sub">
            Publishing-pipeline metrics for the last <?= (int) $days ?> days. These describe how reliably LinkEasy
            publishes — they are not Facebook engagement statistics.
        </p>
    </div>
    <div class="page-head__actions">
        <div class="tabs" style="border:0;margin:0">
            <?php foreach ([7, 30, 90] as $option): ?>
                <a class="<?= $days === $option ? 'is-active' : '' ?>" href="/analytics?days=<?= $option ?>"><?= $option ?> days</a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="grid grid--4 mb-2">
    <div class="card"><div class="card__body"><div class="stat">
        <span class="stat__label">Jobs</span><span class="stat__value"><?= (int) $overview['total_jobs'] ?></span>
        <span class="stat__meta"><?= (int) $overview['retries'] ?> retries</span>
    </div></div></div>
    <div class="card"><div class="card__body"><div class="stat stat--ok">
        <span class="stat__label">Published</span><span class="stat__value"><?= (int) $overview['published'] ?></span>
        <span class="stat__meta">success rate <?= $overview['success_rate'] === null ? '—' : Support::e((string) $overview['success_rate']) . '%' ?></span>
    </div></div></div>
    <div class="card"><div class="card__body"><div class="stat stat--bad">
        <span class="stat__label">Failed</span><span class="stat__value"><?= (int) $overview['failed'] ?></span>
        <span class="stat__meta"><?= (int) $overview['action_required'] ?> needed your action</span>
    </div></div></div>
    <div class="card"><div class="card__body"><div class="stat stat--brand">
        <span class="stat__label">Average publish time</span>
        <span class="stat__value"><?= $overview['avg_publish_seconds'] === null ? '—' : Support::e(Ui::humanSeconds((int) $overview['avg_publish_seconds'])) ?></span>
        <span class="stat__meta"><?= $overview['avg_attempts_to_succeed'] === null ? 'no data' : Support::e((string) $overview['avg_attempts_to_succeed']) . ' attempts per success' ?></span>
    </div></div></div>
</div>

<section class="card mb-2">
    <div class="card__head"><h2>Daily throughput</h2></div>
    <div class="card__body">
        <?= Ui::barChart($throughput) ?>
        <div class="row row--between small muted mt-1">
            <span><?= Support::e((string) ($throughput[0]['day'] ?? '')) ?></span>
            <span><span class="dot" style="background:#34d399"></span> published &nbsp; <span class="dot" style="background:#f87171"></span> failed</span>
            <span><?= Support::e((string) (end($throughput)['day'] ?? '')) ?></span>
        </div>
    </div>
</section>

<div class="grid grid--2 mb-2">
    <section class="card">
        <div class="card__head"><h2>Failure categories</h2></div>
        <div class="card__body card__body--flush">
            <?php if ($failures === []): ?>
                <?= Ui::emptyState('No failures in this window', 'Nothing has needed a retry or manual intervention.', 'check') ?>
            <?php else: ?>
                <div class="table-scroll">
                    <table>
                        <thead><tr><th>Category</th><th>Meaning</th><th class="num">Count</th></tr></thead>
                        <tbody>
                        <?php foreach ($failures as $failure): ?>
                            <tr>
                                <td class="mono small"><?= Support::e((string) $failure['code']) ?></td>
                                <td class="small">
                                    <?= Support::e((string) $failure['label']) ?>
                                    <?php if ($failure['retryable']): ?>
                                        <span class="pill pill--info tiny">auto-retried</span>
                                    <?php else: ?>
                                        <span class="pill pill--warn tiny">needs review</span>
                                    <?php endif; ?>
                                </td>
                                <td class="num"><?= (int) $failure['occurrences'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="card">
        <div class="card__head"><h2>Stage timings</h2></div>
        <div class="card__body card__body--flush">
            <?php if ($overview['stage_durations'] === []): ?>
                <?= Ui::emptyState('Not enough history yet', 'Stage timings appear after a few jobs complete.', 'clock') ?>
            <?php else: ?>
                <div class="table-scroll">
                    <table>
                        <thead><tr><th>State</th><th class="num">Transitions</th><th class="num">Average time in state</th></tr></thead>
                        <tbody>
                        <?php foreach ($overview['stage_durations'] as $stage): ?>
                            <tr>
                                <td><?= Ui::status((string) $stage['state']) ?></td>
                                <td class="num"><?= (int) $stage['events'] ?></td>
                                <td class="num"><?= Support::e(Ui::humanSeconds((int) $stage['average_seconds'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<div class="grid grid--3">
    <section class="card">
        <div class="card__head"><h2>By Page</h2></div>
        <div class="card__body card__body--flush">
            <?php if ($byPage === []): ?>
                <p class="muted small" style="padding:16px">No Page activity yet.</p>
            <?php else: ?>
                <div class="table-scroll">
                    <table>
                        <thead><tr><th>Page</th><th class="num">Jobs</th><th class="num">Published</th><th class="num">Failed</th></tr></thead>
                        <tbody>
                        <?php foreach ($byPage as $row): ?>
                            <tr>
                                <td>
                                    <a class="small" href="/pages/<?= (int) $row['id'] ?>"><?= Support::e(Support::truncate((string) $row['page_name'], 26)) ?></a>
                                    <span class="soft tiny"><?= Support::e((string) $row['account_label']) ?></span>
                                </td>
                                <td class="num"><?= (int) $row['jobs'] ?></td>
                                <td class="num"><?= (int) $row['published'] ?></td>
                                <td class="num"><?= (int) $row['failed'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="card">
        <div class="card__head"><h2>By account</h2></div>
        <div class="card__body card__body--flush">
            <?php if ($byAccount === []): ?>
                <p class="muted small" style="padding:16px">No account activity yet.</p>
            <?php else: ?>
                <div class="table-scroll">
                    <table>
                        <thead><tr><th>Account</th><th>Status</th><th class="num">Jobs</th><th class="num">Failed</th></tr></thead>
                        <tbody>
                        <?php foreach ($byAccount as $row): ?>
                            <tr>
                                <td class="small"><?= Support::e(Support::truncate((string) $row['label'], 24)) ?></td>
                                <td><?= Ui::status((string) $row['account_status']) ?></td>
                                <td class="num"><?= (int) $row['jobs'] ?></td>
                                <td class="num"><?= (int) $row['failed'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="card">
        <div class="card__head"><h2>Worker performance</h2></div>
        <div class="card__body card__body--flush">
            <?php if ($byWorker === []): ?>
                <p class="muted small" style="padding:16px">No workers have processed jobs yet.</p>
            <?php else: ?>
                <div class="table-scroll">
                    <table>
                        <thead><tr><th>Machine</th><th class="num">Jobs</th><th class="num">Published</th><th class="num">Failed</th></tr></thead>
                        <tbody>
                        <?php foreach ($byWorker as $row): ?>
                            <tr>
                                <td class="small">
                                    <a href="/workers/<?= (int) $row['id'] ?>"><?= Support::e(Support::truncate((string) $row['name'], 22)) ?></a>
                                    <?= Ui::status((string) $row['status']) ?>
                                </td>
                                <td class="num"><?= (int) $row['jobs'] ?></td>
                                <td class="num"><?= (int) $row['published'] ?></td>
                                <td class="num"><?= (int) $row['failed'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>
