<?php
declare(strict_types=1);
use App\Core\Support;
use App\Core\Ui;

/** @var list<array> $notifications @var bool $unreadOnly @var int $unreadCount */
?>
<div class="page-head">
    <div class="page-head__text">
        <h1>Notifications</h1>
        <p class="page-head__sub">
            <?= (int) $unreadCount ?> unread. The Windows app raises the same events as native notifications.
        </p>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="/notifications<?= $unreadOnly ? '' : '?unread=1' ?>">
            <?= Ui::icon('eye', 16) ?> <?= $unreadOnly ? 'Show all' : 'Unread only' ?>
        </a>
        <form method="post" action="/notifications/clear" class="inline-form">
            <input type="hidden" name="_csrf" value="<?= Support::e($csrfToken) ?>">
            <button class="btn" type="submit"><?= Ui::icon('check', 16) ?> Mark all read</button>
        </form>
    </div>
</div>

<section class="card">
    <div class="card__body card__body--flush">
        <?php if ($notifications === []): ?>
            <?= Ui::emptyState('Nothing to show', 'Notifications appear when posts publish, fail, or need your attention.', 'bell') ?>
        <?php else: ?>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Level</th><th>Notification</th><th>When</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($notifications as $note): ?>
                        <tr style="<?= $note['read_at'] === null ? 'font-weight:550' : '' ?>">
                            <td>
                                <?php
                                $icon = match ((string) $note['level']) {
                                    'success' => 'check',
                                    'action_required' => 'alert',
                                    'error' => 'x',
                                    'warning' => 'alert',
                                    default => 'bell',
                                };
                                echo Ui::icon($icon, 16);
                                ?>
                            </td>
                            <td>
                                <strong><?= Support::e((string) $note['title']) ?></strong>
                                <?php if (!empty($note['body'])): ?>
                                    <p class="small muted mb-0"><?= Support::e((string) $note['body']) ?></p>
                                <?php endif; ?>
                                <?php if ($note['read_at'] === null): ?>
                                    <span class="pill pill--info tiny">unread</span>
                                <?php endif; ?>
                            </td>
                            <td class="small muted nowrap"><?= Support::e(Ui::ago((string) $note['created_at'])) ?></td>
                            <td class="nowrap">
                                <?php if (!empty($note['link'])): ?>
                                    <a class="btn btn--sm" href="<?= Support::e((string) $note['link']) ?>">Open</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
