<?php
declare(strict_types=1);
use App\Core\Support;
use App\Core\Ui;
?>
<div class="card">
    <div class="card__body">
        <div class="empty">
            <?= Ui::icon('alert', 30) ?>
            <p class="empty__title"><?= Support::e($message ?? 'Something went wrong.') ?></p>
            <p class="empty__hint">
                The incident has been written to the application log. If it keeps happening, export diagnostics
                from the admin console and include the archive in your support request.
            </p>
            <a class="btn btn--primary" href="/dashboard">Back to dashboard</a>
        </div>
    </div>
</div>
