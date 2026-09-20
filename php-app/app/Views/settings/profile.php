<?php
declare(strict_types=1);
use App\Core\Support;
use App\Core\Ui;

/** @var array $me @var list<string> $timezones */
?>
<div class="page-head">
    <div class="page-head__text">
        <h1>Your profile</h1>
        <p class="page-head__sub">Signed in as <span class="mono small"><?= Support::e((string) $me['email']) ?></span></p>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="/settings"><?= Ui::icon('settings', 16) ?> Workspace settings</a>
    </div>
</div>

<div class="grid grid--2">
    <section class="card">
        <div class="card__head"><h2>Details</h2></div>
        <div class="card__body">
            <form method="post" action="/settings/profile">
                <input type="hidden" name="_csrf" value="<?= Support::e($csrfToken) ?>">
                <div class="field">
                    <label class="field__label" for="name">Name</label>
                    <input id="name" name="name" type="text" value="<?= Support::e((string) $me['name']) ?>" maxlength="120" required>
                </div>
                <div class="field">
                    <label class="field__label" for="timezone">Timezone</label>
                    <select id="timezone" name="timezone">
                        <?php foreach ($timezones as $tz): ?>
                            <option value="<?= Support::e($tz) ?>"<?= $me['timezone'] === $tz ? ' selected' : '' ?>><?= Support::e($tz) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn btn--primary" type="submit"><?= Ui::icon('check', 16) ?> Save profile</button>
            </form>

            <hr class="hr">
            <dl class="kv">
                <dt>Role</dt><dd><?= Support::e((string) $me['role']) ?></dd>
                <dt>Status</dt><dd><?= Ui::status((string) $me['status']) ?></dd>
                <dt>Last sign-in</dt><dd><?= Support::e(Ui::ago((string) ($me['last_login_at'] ?? ''))) ?></dd>
                <dt>Member since</dt><dd><?= Support::e(Ui::localTime((string) $me['created_at'], $timezone, 'M j, Y')) ?></dd>
            </dl>
        </div>
    </section>

    <section class="card">
        <div class="card__head"><h2>Change password</h2></div>
        <div class="card__body">
            <form method="post" action="/settings/password">
                <input type="hidden" name="_csrf" value="<?= Support::e($csrfToken) ?>">
                <div class="field">
                    <label class="field__label" for="current_password">Current password</label>
                    <input id="current_password" name="current_password" type="password" required autocomplete="current-password">
                </div>
                <div class="field">
                    <label class="field__label" for="new_password">New password</label>
                    <input id="new_password" name="new_password" type="password" required minlength="12" autocomplete="new-password">
                    <p class="field__hint">At least 12 characters with upper case, lower case and a number.</p>
                </div>
                <div class="field">
                    <label class="field__label" for="new_password_confirmation">Confirm new password</label>
                    <input id="new_password_confirmation" name="new_password_confirmation" type="password" required autocomplete="new-password">
                </div>
                <button class="btn btn--primary" type="submit"><?= Ui::icon('shield', 16) ?> Update password</button>
            </form>

            <div class="alert alert--info mt-2">
                <?= Ui::icon('shield', 16) ?>
                <div class="small">
                    This password protects your LinkEasy workspace only. Facebook sign-in happens separately, in your
                    own browser, and is never stored here.
                </div>
            </div>
        </div>
    </section>
</div>
