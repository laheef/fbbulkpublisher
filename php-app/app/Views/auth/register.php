<?php
declare(strict_types=1);
use App\Core\Support;
use App\Core\Ui;
use App\Core\Clock;
?>
<div class="auth">
    <div>
        <div class="auth__card">
            <div class="auth__brand">
                <span class="sidebar__mark">LE</span>
                <span><?= Support::e($appName ?? 'LinkEasy Publisher') ?></span>
            </div>
            <h1 style="margin-bottom:4px">Create your workspace</h1>
            <p class="muted small mb-2">You will connect Facebook accounts afterwards, from the Windows app.</p>

            <form method="post" action="/register" novalidate>
                <input type="hidden" name="_csrf" value="<?= Support::e($csrfToken ?? '') ?>">

                <div class="field">
                    <label class="field__label" for="name">Your name</label>
                    <input id="name" name="name" type="text" required maxlength="120" autocomplete="name">
                </div>

                <div class="field">
                    <label class="field__label" for="email">Email address</label>
                    <input id="email" name="email" type="email" required autocomplete="username">
                </div>

                <div class="field">
                    <label class="field__label" for="password">Password</label>
                    <input id="password" name="password" type="password" required minlength="12" autocomplete="new-password">
                    <p class="field__hint">At least 12 characters, with upper case, lower case and a number.</p>
                </div>

                <div class="field">
                    <label class="field__label" for="timezone">Your timezone</label>
                    <select id="timezone" name="timezone">
                        <?php foreach (Clock::commonTimezones() as $tz): ?>
                            <option value="<?= Support::e($tz) ?>"<?= $tz === 'UTC' ? ' selected' : '' ?>><?= Support::e($tz) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="field__hint">Schedules are stored in UTC and shown in this timezone.</p>
                </div>

                <button class="btn btn--primary btn--block" type="submit"><?= Ui::icon('check', 16) ?> Create workspace</button>
            </form>

            <p class="auth__note">Already have one? <a href="/login">Sign in</a></p>
        </div>
    </div>
</div>
