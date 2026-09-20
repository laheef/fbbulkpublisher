<?php
declare(strict_types=1);
use App\Core\Support;
use App\Core\Ui;
?>
<div class="card">
    <div class="card__body">
        <div class="empty">
            <?= Ui::icon('search', 30) ?>
            <p class="empty__title"><?= Support::e($message ?? 'We could not find that page.') ?></p>
            <p class="empty__hint">Check the address, or head back to the dashboard.</p>
            <a class="btn btn--primary" href="/dashboard">Back to dashboard</a>
        </div>
    </div>
</div>
