<?php
declare(strict_types=1);
use App\Core\Support;

/** @var string $content */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= Support::e($csrfToken ?? '') ?>">
    <title><?= Support::e($title ?? '') ?> · <?= Support::e($appName ?? 'LinkEasy Publisher') ?></title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<?= $content ?>
<script src="/assets/app.js" defer></script>
</body>
</html>
