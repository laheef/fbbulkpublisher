<?php
declare(strict_types=1);
use App\Core\Support;
use App\Core\Ui;
?>
<div class="card">
    <div class="card__body">
        <div class="empty">
            <?= Ui::icon('shield', 30) ?>
            <p class="empty__title"><?= Support::e($message ?? 'You are not allowed to do that.') ?></p>
            <p class="empty__hint">If you believe this is a mistake, ask an administrator to review your access.</p>
            <a class="btn btn--primary" href="/dashboard">Back to dashboard</a>
        </div>
    </div>
</div>
