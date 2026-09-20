<?php
declare(strict_types=1);
use App\Core\Support;
use App\Core\Ui;
?>
<div class="card">
    <div class="card__body">
        <div class="empty">
            <?= Ui::icon('clock', 30) ?>
            <p class="empty__title"><?= Support::e($message ?? 'Your session expired.') ?></p>
            <p class="empty__hint">For your security, forms are protected against cross-site request forgery.
                Reload the page and try again.</p>
            <a class="btn btn--primary" href="" onclick="window.location.reload(); return false;">Reload the page</a>
        </div>
    </div>
</div>
