<?php
declare(strict_types=1);
use App\Core\Support;
use App\Core\Ui;

$flash = $flash ?? null;
?>
<div class="auth">
    <div>
        <div class="auth__card">
            <div class="auth__brand">
                <span class="sidebar__mark">LE</span>
                <span><?= Support::e($appName ?? 'LinkEasy Publisher') ?></span>
            </div>
            <h1 style="margin-bottom:4px">Sign in</h1>
            <p class="muted small mb-2">Your workspace controls content, scheduling and the publishing queue.</p>

            <?php if ($flash !== null): ?>
                <div class="flash flash--<?= Support::e((string) $flash['type']) ?>"><?= Support::e((string) $flash['message']) ?></div>
            <?php endif; ?>

            <form method="post" action="/login" autocomplete="on" novalidate>
                <input type="hidden" name="_csrf" value="<?= Support::e($csrfToken ?? '') ?>">

                <div class="field">
                    <label class="field__label" for="email">Email address</label>
                    <input id="email" name="email" type="email" required autofocus autocomplete="username"
                           value="<?= Support::e((string) ($_POST['email'] ?? '')) ?>">
                </div>

                <div class="field">
                    <label class="field__label" for="password">Password</label>
                    <input id="password" name="password" type="password" required autocomplete="current-password">
                    <p class="field__hint">Passwords are hashed with Argon2id. We never store Facebook credentials.</p>
                </div>

                <button class="btn btn--primary btn--block" type="submit"><?= Ui::icon('logout', 16) ?> Sign in</button>
            </form>

            <p class="auth__note">No workspace yet? <a href="/register">Create one</a></p>
        </div>

        <p class="auth__aside">
            Signing in to this dashboard does <strong>not</strong> sign you in to Facebook.
            Facebook authentication happens inside the LinkEasy Windows app, in your own browser.
        </p>
    </div>
</div>
