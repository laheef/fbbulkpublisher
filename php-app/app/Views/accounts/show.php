<?php
declare(strict_types=1);
use App\Core\Support;
use App\Core\Ui;

/** @var array $account @var list<array> $pages @var list<array> $workers */
?>
<div class="page-head">
    <div class="page-head__text">
        <h1><?= Support::e((string) $account['label']) ?></h1>
        <p class="page-head__sub">
            <?= Ui::status((string) $account['status']) ?>
            <span class="mono small"><?= Support::e((string) $account['profile_ref']) ?></span>
        </p>
    </div>
    <div class="page-head__actions">
        <form method="post" action="/accounts/<?= (int) $account['id'] ?>/connect" class="inline-form">
            <input type="hidden" name="_csrf" value="<?= Support::e($csrfToken) ?>">
            <button class="btn btn--primary" type="submit"><?= Ui::icon('play', 16) ?> Connect / re-open browser</button>
        </form>
        <a class="btn" href="/accounts"><?= Ui::icon('x', 16) ?> Back</a>
    </div>
</div>

<div class="grid grid--sidebar">
    <section class="card">
        <div class="card__head"><h2>Select the Pages this account may publish to</h2></div>
        <form method="post" action="/accounts/<?= (int) $account['id'] ?>/pages">
            <input type="hidden" name="_csrf" value="<?= Support::e($csrfToken) ?>">
            <div class="card__body">
                <?php if ($pages === []): ?>
                    <?= Ui::emptyState('No Pages discovered yet', 'Click Connect, sign in to Facebook in the browser window, then refresh this page.', 'pages') ?>
                <?php else: ?>
                    <p class="small muted">
                        Disabled Pages stay in LinkEasy but are skipped by the dispatcher — useful when you want to
                        pause one brand without deleting its history.
                    </p>
                    <div class="page-picker">
                        <?php foreach ($pages as $page): ?>
                            <label class="check">
                                <input type="checkbox" name="enabled_page_ids[]" value="<?= (int) $page['id'] ?>"
                                    <?= $page['status'] === 'ENABLED' ? 'checked' : '' ?>>
                                <span class="check__text">
                                    <strong><?= Support::e((string) $page['page_name']) ?></strong>
                                    <span class="check__meta">
                                        ID <?= Support::e((string) $page['page_id']) ?>
                                        <?php if (!empty($page['category'])): ?> · <?= Support::e((string) $page['category']) ?><?php endif; ?>
                                        · <?= Ui::status((string) $page['status']) ?>
                                    </span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php if ($pages !== []): ?>
                <div class="card__foot">
                    <button class="btn btn--primary" type="submit"><?= Ui::icon('check', 16) ?> Save selection</button>
                </div>
            <?php endif; ?>
        </form>
    </section>

    <div class="stack">
        <section class="card">
            <div class="card__head"><h2>Account details</h2></div>
            <div class="card__body">
                <form method="post" action="/accounts/<?= (int) $account['id'] ?>">
                    <input type="hidden" name="_csrf" value="<?= Support::e($csrfToken) ?>">
                    <div class="field">
                        <label class="field__label" for="label">Label</label>
                        <input id="label" name="label" type="text" value="<?= Support::e((string) $account['label']) ?>" maxlength="120">
                    </div>
                    <button class="btn btn--sm" type="submit">Save label</button>
                </form>

                <hr class="hr">
                <dl class="kv">
                    <dt>Status</dt><dd><?= Ui::status((string) $account['status']) ?></dd>
                    <dt>Facebook account</dt><dd><?= Support::e((string) ($account['fb_account_name'] ?? '—')) ?></dd>
                    <dt>Pages</dt><dd><?= (int) $account['page_count'] ?></dd>
                    <dt>Last verified</dt><dd><?= Support::e($account['last_verified_at'] === null ? 'never' : Ui::ago((string) $account['last_verified_at'])) ?></dd>
                    <dt>Machine</dt>
                    <dd>
                        <?php
                        $machine = null;
                        foreach ($workers as $worker) {
                            if ((int) $worker['id'] === (int) $account['worker_id']) { $machine = $worker; break; }
                        }
                        ?>
                        <?php if ($machine !== null): ?>
                            <a href="/workers/<?= (int) $machine['id'] ?>"><?= Support::e((string) $machine['name']) ?></a>
                            <?= Ui::status((string) $machine['status']) ?>
                        <?php else: ?>
                            <span class="soft">not attached</span>
                        <?php endif; ?>
                    </dd>
                </dl>
            </div>
        </section>

        <section class="card">
            <div class="card__head"><h2>Security</h2></div>
            <div class="card__body small">
                <p>This server stores no Facebook password, cookie or session token. The browser profile lives
                    only on your Windows machine, encrypted with Windows DPAPI under your user account.</p>
                <p class="mb-0">If Facebook asks for a verification while publishing, the worker pauses and asks you
                    to complete it in the browser. LinkEasy never bypasses those checks.</p>
            </div>
        </section>
    </div>
</div>
