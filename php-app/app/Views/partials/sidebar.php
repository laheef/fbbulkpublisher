<?php
declare(strict_types=1);
use App\Core\Support;
use App\Core\Ui;

/** @var string $currentPath */
/** @var int $unreadCount */
/** @var bool $isAdmin */

$path = $currentPath ?? '/';
$isActive = static function (string $prefix) use ($path): string {
    if ($prefix === '/dashboard') {
        return ($path === '/' || $path === '/dashboard') ? ' is-active' : '';
    }
    return str_starts_with($path, $prefix) ? ' is-active' : '';
};

$groups = [
    'Publishing' => [
        ['/dashboard', 'dashboard', 'Dashboard', null],
        ['/posts',     'posts',     'Posts', null],
        ['/calendar',  'calendar',  'Calendar', null],
        ['/queue',     'queue',     'Queue', $queueCount ?? null],
    ],
    'Facebook' => [
        ['/accounts', 'accounts', 'Accounts', null],
        ['/pages',    'pages',    'Pages', null],
        ['/media',    'media',    'Media', null],
    ],
    'Operations' => [
        ['/workers',     'workers',   'Workers', null],
        ['/analytics',   'analytics', 'Analytics', null],
        ['/notifications', 'bell',    'Notifications', $unreadCount ?? null],
        ['/settings',    'settings',  'Settings', null],
    ],
];
?>
<aside class="sidebar">
    <div class="sidebar__brand">
        <span class="sidebar__mark">LE</span>
        <span>
            <?= Support::e($appName ?? 'LinkEasy Publisher') ?>
            <small>v<?= Support::e($appVersion ?? '1.0.0') ?> · control plane</small>
        </span>
    </div>

    <?php foreach ($groups as $groupName => $links): ?>
        <div class="sidebar__section"><?= Support::e($groupName) ?></div>
        <nav class="sidebar__nav">
            <?php foreach ($links as [$href, $icon, $label, $count]): ?>
                <a class="sidebar__link<?= $isActive($href) ?>" href="<?= Support::e($href) ?>">
                    <?= Ui::icon($icon, 17) ?>
                    <span><?= Support::e($label) ?></span>
                    <?php if ($count !== null && (int) $count > 0): ?>
                        <span class="count"><?= (int) $count ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>
    <?php endforeach; ?>

    <?php if (($isAdmin ?? false)): ?>
        <div class="sidebar__section">Administration</div>
        <nav class="sidebar__nav">
            <a class="sidebar__link<?= $isActive('/admin') ?>" href="/admin">
                <?= Ui::icon('admin', 17) ?><span>Admin console</span>
            </a>
        </nav>
    <?php endif; ?>

    <div class="sidebar__foot">
        <a href="/posts/new" class="btn btn--primary btn--block btn--sm"><?= Ui::icon('plus', 15) ?> New post</a>
        <p class="tiny" style="margin:10px 0 0">
            PHP controls scheduling and the queue. Your Windows app performs authorised,
            in-browser publishing.
        </p>
    </div>
</aside>
