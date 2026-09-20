<?php
declare(strict_types=1);
use App\Core\Support;
use App\Core\Ui;

/** @var list<array> $accounts @var list<array> $workers */
?>
<div class="page-head">
    <div class="page-head__text">
        <h1>Accounts</h1>
        <p class="page-head__sub">
            Each account owns one isolated browser profile on one Windows machine.
            Profiles are never shared between accounts, and Facebook credentials never reach this server.
        </p>
    </div>
    <div class="page-head__actions">
        <a class="btn btn--primary" href="#new-account"><?= Ui::icon('plus', 16) ?> New account</a>
    </div>
</div>

<?php if ($workers === []): ?>
    <div class="alert alert--warn mb-2">
        <?= Ui::icon('alert', 18) ?>
        <div>
            <strong>No Windows machine is registered yet.</strong>
            <p class="small mb-0 mt-1">
                Install <em>LinkEasy Facebook Publisher Setup.exe</em> on a Windows 10/11 PC, launch it, and sign in
                to this workspace. The machine then appears under <a href="/workers">Workers</a> and can connect
                Facebook accounts.
            </p>
        </div>
    </div>
<?php endif; ?>

<?php if ($accounts === []): ?>
    <div class="card">
        <?= Ui::emptyState(
            'No Facebook accounts connected',
            'Create an account slot, then click Connect to open a browser on your PC and sign in to Facebook normally.',
            'accounts',
            '#new-account',
            'Create account slot'
        ) ?>
    </div>
<?php endif; ?>

<div class="stack">
    <?php foreach ($accounts as $account): ?>
        <section class="card">
            <div class="card__head">
                <h2><?= Support::e((string) $account['label']) ?></h2>
                <?= Ui::status((string) $account['status']) ?>
                <span class="spacer"></span>
                <span class="small muted">
                    Profile <span class="mono"><?= Support::e(substr((string) $account['profile_ref'], 0, 18)) ?>…</span>
                </span>
            </div>
            <div class="card__body">
                <div class="grid grid--2">
                    <div>
                        <dl class="kv">
                            <dt>Facebook identity</dt>
                            <dd><?= Support::e((string) ($account['fb_account_name'] ?? '— not detected yet —')) ?></dd>
                            <dt>Pages</dt>
                            <dd><?= (int) $account['page_count'] ?> discovered</dd>
                            <dt>Last verified</dt>
                            <dd><?= Support::e($account['last_verified_at'] === null ? 'never' : Ui::ago((string) $account['last_verified_at'])) ?></dd>
                        </dl>

                        <?php if (!empty($account['last_error'])): ?>
                            <div class="alert alert--warn mt-2">
                                <?= Ui::icon('alert', 16) ?>
                                <div class="small">
                                    <strong><?= Support::e(Ui::humanize((string) $account['last_error_code'])) ?></strong><br>
                                    <?= Support::e((string) $account['last_error']) ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="row mt-2">
                            <form method="post" action="/accounts/<?= (int) $account['id'] ?>/connect" class="inline-form">
                                <input type="hidden" name="_csrf" value="<?= Support::e($csrfToken) ?>">
                                <button class="btn btn--primary btn--sm" type="submit"><?= Ui::icon('play', 15) ?> Connect Facebook</button>
                            </form>

                            <button class="btn btn--sm"
                                    data-action="/accounts/<?= (int) $account['id'] ?>/verify"
                                    data-reload="true"
                                    data-success="Verification requested.">
                                <?= Ui::icon('check', 15) ?> Verify
                            </button>

                            <button class="btn btn--sm"
                                    data-action="/accounts/<?= (int) $account['id'] ?>/toggle"
                                    data-reload="true">
                                <?= Ui::icon('pause', 15) ?> <?= $account['status'] === 'DISABLED' ? 'Enable' : 'Disable' ?>
                            </button>

                            <a class="btn btn--sm" href="/accounts/<?= (int) $account['id'] ?>">Details</a>
                        </div>
                    </div>

                    <div>
                        <h3 class="small">Pages on this account</h3>
                        <?php if ($account['pages'] === []): ?>
                            <p class="muted small">No Pages detected yet. Click <strong>Connect Facebook</strong>, sign in
                                in the browser window, and the Page list appears here.</p>
                        <?php else: ?>
                            <ul class="stack" style="list-style:none;margin:0;padding:0;gap:6px">
                                <?php foreach (array_slice($account['pages'], 0, 8) as $page): ?>
                                    <li class="row row--between">
                                        <span class="small"><?= Support::e((string) $page['page_name']) ?></span>
                                        <?= Ui::status((string) $page['status']) ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php if (count($account['pages']) > 8): ?>
                                <p class="small muted mt-1">+ <?= count($account['pages']) - 8 ?> more</p>
                            <?php endif; ?>
                        <?php endif; ?>

                        <div class="row mt-2">
                            <button class="btn btn--sm"
                                    data-action="/accounts/<?= (int) $account['id'] ?>/reset-profile"
                                    data-confirm="Resetting the browser profile deletes the stored Facebook session on that PC. You will need to sign in to Facebook again. Continue?"
                                    data-reload="true"
                                    data-success="Profile reset. Sign in to Facebook again on your PC.">
                                <?= Ui::icon('refresh', 15) ?> Reset profile
                            </button>
                            <button class="btn btn--danger btn--sm"
                                    data-action="/accounts/<?= (int) $account['id'] ?>/remove"
                                    data-confirm="Remove this account and its Pages from LinkEasy? Jobs and history for it are deleted too."
                                    data-reload="true"
                                    data-success="Account removed.">
                                <?= Ui::icon('trash', 15) ?> Remove
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    <?php endforeach; ?>
</div>

<div class="modal" id="new-account">
    <div class="modal__panel">
        <div class="modal__head">
            <h3>New Facebook account slot</h3>
            <a class="btn btn--ghost btn--sm" href="#"><?= Ui::icon('x', 16) ?></a>
        </div>
        <form method="post" action="/accounts">
            <input type="hidden" name="_csrf" value="<?= Support::e($csrfToken) ?>">
            <div class="modal__body">
                <div class="field">
                    <label class="field__label" for="label">Label</label>
                    <input id="label" name="label" type="text" required maxlength="120" placeholder="e.g. Main brand account">
                    <p class="field__hint">Just a name for you. The real Facebook identity is detected after sign-in.</p>
                </div>

                <div class="field">
                    <label class="field__label" for="worker_id">Windows machine</label>
                    <select id="worker_id" name="worker_id">
                        <option value="0">Automatic — first available machine</option>
                        <?php foreach ($workers as $worker): ?>
                            <option value="<?= (int) $worker['id'] ?>">
                                <?= Support::e((string) $worker['name']) ?> · <?= Support::e((string) $worker['status']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="field__hint">The profile is created on that machine and never copied elsewhere.</p>
                </div>

                <div class="alert alert--info">
                    <?= Ui::icon('shield', 16) ?>
                    <div class="small">
                        Sign-in happens in a normal browser window on your PC, exactly as if you had opened Facebook
                        yourself — including any 2FA or security check Facebook asks for. LinkEasy never sees your
                        password and never solves CAPTCHAs for you.
                    </div>
                </div>
            </div>
            <div class="modal__foot">
                <a class="btn" href="#">Cancel</a>
                <button class="btn btn--primary" type="submit">Create account slot</button>
            </div>
        </form>
    </div>
</div>
