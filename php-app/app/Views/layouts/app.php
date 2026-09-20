<?php
declare(strict_types=1);
use App\Core\Support;
use App\Core\Ui;

/** @var string $content */
/** @var string $title */
$title = $title ?? 'Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= Support::e($csrfToken ?? '') ?>">
    <meta name="color-scheme" content="light">
    <title><?= Support::e($title ?? '') ?> · <?= Support::e($appName ?? 'LinkEasy Publisher') ?></title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<div class="shell">
    <?php require __DIR__ . '/../partials/sidebar.php'; ?>

    <div class="main">
        <header class="topbar">
            <span class="topbar__title"><?= Support::e($title ?? '') ?></span>
            <span class="topbar__spacer"></span>
            <span class="topbar__user small">
                <?= Ui::icon('clock', 15) ?>
                <span title="All times are stored in UTC and displayed in this timezone"><?= Support::e($timezone ?? 'UTC') ?></span>
            </span>
            <a class="btn btn--ghost btn--sm" href="/notifications" title="Notifications">
                <?= Ui::icon('bell', 16) ?>
                <?php if (($unreadCount ?? 0) > 0): ?>
                    <span class="pill pill--warn"><?= (int) $unreadCount ?></span>
                <?php endif; ?>
            </a>
            <span class="topbar__user">
                <span class="avatar"><?= Support::e(strtoupper(mb_substr((string) ($user['name'] ?? 'U'), 0, 1))) ?></span>
                <span class="small">
                    <?= Support::e((string) ($user['name'] ?? 'Signed in')) ?>
                    <?php if (($isAdmin ?? false)): ?><span class="pill pill--info">admin</span><?php endif; ?>
                </span>
            </span>
            <form method="post" action="/logout" class="inline-form">
                <input type="hidden" name="_csrf" value="<?= Support::e($csrfToken ?? '') ?>">
                <button class="btn btn--ghost btn--sm" type="submit" title="Sign out"><?= Ui::icon('logout', 16) ?></button>
            </form>
        </header>

        <main class="content">
            <div id="flash-host">
                <?php if (!empty($flash)): ?>
                    <div class="flash flash--<?= Support::e((string) ($flash['type'] ?? 'info')) ?>">
                        <?= Support::e((string) ($flash['message'] ?? '')) ?>
                    </div>
                <?php endif; ?>
            </div>

            <?= $content ?>
        </main>
    </div>
</div>
<script src="/assets/app.js" defer></script>
</body>
</html>
