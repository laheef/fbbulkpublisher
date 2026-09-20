<?php
declare(strict_types=1);
use App\Core\Support;
use App\Core\Ui;

/** @var list<array> $weeks @var string $monthLabel @var string $prevMonth @var string $nextMonth @var int $total @var list<array> $schedules */
$toneFor = static function (string $status): string {
    return match ($status) {
        'PUBLISHED' => 'ok',
        'FAILED', 'CANCELLED' => 'bad',
        'USER_ACTION_REQUIRED', 'ACCOUNT_REAUTH_REQUIRED' => 'warn',
        'SCHEDULED', 'QUEUED' => 'info',
        default => 'active',
    };
};
?>
<div class="page-head">
    <div class="page-head__text">
        <h1>Calendar</h1>
        <p class="page-head__sub">
            <?= Support::e($monthLabel) ?> · <?= (int) $total ?> job(s) · shown in <?= Support::e($timezone) ?>
        </p>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="/calendar?month=<?= Support::e($prevMonth) ?>"><?= Ui::icon('calendar', 16) ?> Previous</a>
        <a class="btn" href="/calendar">Today</a>
        <a class="btn" href="/calendar?month=<?= Support::e($nextMonth) ?>">Next</a>
        <a class="btn btn--primary" href="/posts/new"><?= Ui::icon('plus', 16) ?> New post</a>
    </div>
</div>

<section class="card mb-2">
    <div class="card__body card__body--flush">
        <div class="table-scroll">
            <table style="min-width:900px">
                <thead>
                    <tr>
                        <th style="width:140px">Monday</th><th>Tuesday</th><th>Wednesday</th>
                        <th>Thursday</th><th>Friday</th><th>Saturday</th><th>Sunday</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($weeks as $week): ?>
                    <tr>
                        <?php foreach ($week as $day): ?>
                            <td style="vertical-align:top;height:120px;background:<?= $day['in_month'] ? '#fff' : '#fafbfd' ?>">
                                <div class="row row--between mb-1">
                                    <span class="small <?= $day['is_today'] ? '' : 'muted' ?>"
                                          style="<?= $day['is_today'] ? 'font-weight:700;color:var(--brand)' : '' ?>">
                                        <?= (int) $day['day'] ?>
                                    </span>
                                    <?php if (count($day['jobs']) > 0): ?>
                                        <span class="pill pill--muted tiny"><?= count($day['jobs']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php foreach (array_slice($day['jobs'], 0, 4) as $job): ?>
                                    <a class="small" style="display:block;padding:3px 6px;border-radius:5px;margin-bottom:3px;
                                              background:var(--<?= $toneFor((string) $job['status']) ?>-soft);
                                              color:var(--<?= $toneFor((string) $job['status']) ?>);text-decoration:none"
                                       href="/queue?job=<?= (int) $job['id'] ?>"
                                       title="<?= Support::e($job['page_name'] . ' — ' . $job['status']) ?>">
                                        <span class="mono tiny"><?= Support::e((string) $job['local_time']) ?></span>
                                        <?= Support::e(Support::truncate((string) $job['page_name'], 18)) ?>
                                    </a>
                                <?php endforeach; ?>
                                <?php if (count($day['jobs']) > 4): ?>
                                    <span class="tiny muted">+ <?= count($day['jobs']) - 4 ?> more</span>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<div class="grid grid--2">
    <section class="card">
        <div class="card__head"><h2>Active schedules</h2></div>
        <div class="card__body card__body--flush">
            <?php if ($schedules === []): ?>
                <?= Ui::emptyState('No repeating schedules', 'One-off scheduled posts appear in the calendar grid above.', 'clock') ?>
            <?php else: ?>
                <div class="table-scroll">
                    <table>
                        <thead><tr><th>Post</th><th>Next run</th><th>Repeat</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($schedules as $schedule): ?>
                            <tr>
                                <td><span class="truncate"><?= Support::e(Support::excerpt((string) $schedule['caption'], 50)) ?></span></td>
                                <td class="small nowrap"><?= Support::e(Ui::localTime($schedule['next_run_at'] === null ? null : (string) $schedule['next_run_at'], $timezone, 'M j, H:i')) ?></td>
                                <td class="small"><?= Support::e(Ui::humanize((string) $schedule['recurrence'])) ?></td>
                                <td><?= Ui::status((string) $schedule['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="card">
        <div class="card__head"><h2>Legend</h2></div>
        <div class="card__body">
            <dl class="kv">
                <dt>Time handling</dt>
                <dd class="small">Every timestamp is stored in UTC and converted for display. Changing your timezone
                    never moves an existing schedule.</dd>
                <dt>Spacing</dt>
                <dd class="small">When you space Pages apart, each Page gets its own job offset by the interval you chose.</dd>
                <dt>Failure behaviour</dt>
                <dd class="small">A failed Page never blocks the others — each job is independent and retried on its own.</dd>
            </dl>
        </div>
    </section>
</div>
