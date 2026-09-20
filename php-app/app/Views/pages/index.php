<?php
declare(strict_types=1);
use App\Core\Support;
use App\Core\Ui;

/** @var list<array> $pages @var array $filters @var list<array> $accounts @var array $summary @var list<string> $statuses */
?>
<div class="page-head">
    <div class="page-head__text">
        <h1>Pages</h1>
        <p class="page-head__sub">
            <?= (int) $summary['enabled'] ?> enabled · <?= (int) $summary['disabled'] ?> disabled ·
            <?= (int) $summary['errored'] ?> need attention
        </p>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="/accounts"><?= Ui::icon('accounts', 16) ?> Manage accounts</a>
    </div>
</div>

<form class="card mb-2" method="get" action="/pages">
    <div class="card__body">
        <div class="form-row">
            <div class="field">
                <label class="field__label" for="q">Search</label>
                <input id="q" name="q" type="text" value="<?= Support::e((string) $filters['search']) ?>" placeholder="Page name or Facebook Page ID">
            </div>
            <div class="field">
                <label class="field__label" for="status">Status</label>
                <select id="status" name="status">
                    <option value="">Any status</option>
                    <?php foreach ($statuses as $status): ?>
                        <option value="<?= Support::e($status) ?>"<?= $filters['status'] === $status ? ' selected' : '' ?>>
                            <?= Support::e(Ui::humanize($status)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="field__label" for="account">Account</label>
                <select id="account" name="account">
                    <option value="0">All accounts</option>
                    <?php foreach ($accounts as $account): ?>
                        <option value="<?= (int) $account['id'] ?>"<?= (int) $filters['account'] === (int) $account['id'] ? ' selected' : '' ?>>
                            <?= Support::e((string) $account['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="row">
            <button class="btn btn--primary" type="submit"><?= Ui::icon('search', 15) ?> Filter</button>
            <a class="btn" href="/pages">Reset</a>
        </div>
    </div>
</form>

<section class="card">
    <div class="card__body card__body--flush">
        <?php if ($pages === []): ?>
            <?= Ui::emptyState('No Pages match', 'Connect a Facebook account, or relax the filters above.', 'pages', '/accounts', 'Go to accounts') ?>
        <?php else: ?>
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Page</th><th>Account</th><th>Status</th><th class="num">Scheduled</th>
                            <th class="num">Failed</th><th>Last published</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pages as $page): ?>
                        <tr>
                            <td>
                                <strong><?= Support::e((string) $page['page_name']) ?></strong>
                                <span class="soft tiny mono"><?= Support::e((string) $page['page_id']) ?></span>
                            </td>
                            <td class="small">
                                <?= Support::e((string) ($page['account_label'] ?? '—')) ?>
                                <span class="soft tiny"><?= Support::e((string) ($page['worker_name'] ?? 'no machine')) ?></span>
                            </td>
                            <td><?= Ui::status((string) $page['status']) ?></td>
                            <td class="num"><?= (int) $page['scheduled_count'] ?></td>
                            <td class="num"><?= (int) $page['failed_count'] ?></td>
                            <td class="small">
                                <?php if (!empty($page['last_published_at'])): ?>
                                    <?= Support::e(Ui::ago((string) $page['last_published_at'])) ?>
                                    <?php if (!empty($page['last_published_url'])): ?>
                                        <a class="tiny" href="<?= Support::e((string) $page['last_published_url']) ?>" target="_blank" rel="noopener noreferrer">view</a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="soft">never</span>
                                <?php endif; ?>
                            </td>
                            <td class="nowrap">
                                <a class="btn btn--sm" href="/pages/<?= (int) $page['id'] ?>">Open</a>
                                <a class="btn btn--sm" href="/posts/new?page=<?= (int) $page['id'] ?>"><?= Ui::icon('plus', 14) ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
