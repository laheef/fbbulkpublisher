<?php
declare(strict_types=1);
use App\Core\Support;
use App\Core\Ui;

/** @var list<array> $users */
?>
<div class="page-head">
    <div class="page-head__text">
        <h1>Users</h1>
        <p class="page-head__sub">Every workspace is isolated: accounts, Pages, media, posts, jobs, logs and workers.</p>
    </div>
</div>

<nav class="tabs">
    <a href="/admin">Overview</a>
    <a class="is-active" href="/admin/users">Users</a>
    <a href="/admin/workers">Fleet</a>
    <a href="/admin/activity">Activity</a>
    <a href="/admin/errors">Errors</a>
</nav>

<section class="card">
    <div class="card__body card__body--flush">
        <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>User</th><th>Role</th><th>Status</th><th class="num">Accounts</th><th class="num">Pages</th>
                        <th class="num">Posts</th><th class="num">Jobs</th><th class="num">Workers</th>
                        <th>Last sign-in</th><th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td>
                            <strong><?= Support::e((string) $user['name']) ?></strong>
                            <span class="soft tiny mono"><?= Support::e((string) $user['email']) ?></span>
                        </td>
                        <td><?= $user['role'] === 'admin' ? '<span class="pill pill--info">admin</span>' : '<span class="pill pill--muted">user</span>' ?></td>
                        <td><?= Ui::status((string) $user['status']) ?></td>
                        <td class="num"><?= (int) $user['accounts'] ?></td>
                        <td class="num"><?= (int) $user['pages'] ?></td>
                        <td class="num"><?= (int) $user['posts'] ?></td>
                        <td class="num"><?= (int) $user['jobs'] ?></td>
                        <td class="num"><?= (int) $user['workers'] ?></td>
                        <td class="small muted nowrap"><?= Support::e($user['last_login_at'] === null ? 'never' : Ui::ago((string) $user['last_login_at'])) ?></td>
                        <td class="nowrap">
                            <form method="post" action="/admin/users/<?= (int) $user['id'] ?>" class="inline-form">
                                <input type="hidden" name="_csrf" value="<?= Support::e($csrfToken) ?>">
                                <?php if ((string) $user['status'] === 'ACTIVE'): ?>
                                    <button class="btn btn--sm" name="action" value="suspend"
                                            data-confirm="Suspend this workspace? The user is signed out and cannot sign in again.">
                                        Suspend
                                    </button>
                                <?php else: ?>
                                    <button class="btn btn--sm" name="action" value="activate">Activate</button>
                                <?php endif; ?>
                                <?php if ((string) $user['role'] !== 'admin'): ?>
                                    <button class="btn btn--sm" name="action" value="promote">Make admin</button>
                                <?php else: ?>
                                    <button class="btn btn--sm" name="action" value="demote"
                                            data-confirm="Remove administrator rights from this user?">Demote</button>
                                <?php endif; ?>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<div class="alert alert--info mt-2">
    <?= Ui::icon('shield', 16) ?>
    <div class="small">
        Administrators can see platform health and manage users. They cannot read any workspace's Facebook data:
        no cookies, tokens or browser profiles ever reach this server, and job payloads contain only captions and
        page references.
    </div>
</div>
