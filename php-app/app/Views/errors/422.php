<?php
declare(strict_types=1);
use App\Core\Support;
use App\Core\Ui;

/** @var array<string,string> $details */
?>
<div class="card">
    <div class="card__body">
        <div class="alert alert--warn">
            <?= Ui::icon('alert', 18) ?>
            <div>
                <strong><?= Support::e($message ?? 'The submitted data is invalid.') ?></strong>
                <?php if (!empty($details)): ?>
                    <ul class="small" style="margin:8px 0 0 16px; padding:0">
                        <?php foreach ($details as $field => $error): ?>
                            <li><span class="mono"><?= Support::e((string) $field) ?></span> — <?= Support::e((string) $error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
        <p class="mt-2"><a class="btn" href="" onclick="history.back(); return false;">Go back and fix it</a></p>
    </div>
</div>
