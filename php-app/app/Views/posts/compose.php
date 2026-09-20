<?php
declare(strict_types=1);
use App\Core\Clock;
use App\Core\Support;
use App\Core\Ui;

/** @var list<array> $accounts @var list<array> $media @var array $prefill @var string $provider */
$totalAvailable = 0;
foreach ($accounts as $account) {
    $totalAvailable += count($account['pages']);
}
?>
<div class="page-head">
    <div class="page-head__text">
        <h1>New post</h1>
        <p class="page-head__sub">
            Publishing runs through: <?= Support::e($provider) ?>.
            One job is created per selected Page — 100 Pages is 100 jobs, not 100 browsers.
        </p>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="/posts"><?= Ui::icon('x', 16) ?> Cancel</a>
    </div>
</div>

<form method="post" action="/posts" id="composer">
    <input type="hidden" name="_csrf" value="<?= Support::e($csrfToken) ?>">

    <div class="grid grid--sidebar">
        <div class="stack">
            <section class="card">
                <div class="card__head"><h2>Content</h2></div>
                <div class="card__body">
                    <div class="field">
                        <label class="field__label" for="caption">Caption</label>
                        <textarea id="caption" name="caption" maxlength="20000"
                                  placeholder="Write your post…"><?= Support::e((string) ($_POST['caption'] ?? '')) ?></textarea>
                        <div class="row row--between">
                            <span class="field__hint">Emoji and line breaks are preserved exactly.</span>
                            <span class="field__hint" id="caption-counter"></span>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="field">
                            <label class="field__label" for="hashtags">Hashtags</label>
                            <input id="hashtags" name="hashtags" type="text" maxlength="1000" placeholder="#brand #launch">
                            <p class="field__hint">Appended to the caption when publishing.</p>
                        </div>
                        <div class="field">
                            <label class="field__label" for="link_url">Link (optional)</label>
                            <input id="link_url" name="link_url" type="url" maxlength="1000" placeholder="https://example.com">
                        </div>
                    </div>

                    <div class="field">
                        <label class="field__label" for="title">Internal title (optional)</label>
                        <input id="title" name="title" type="text" maxlength="255" placeholder="Only visible to you">
                    </div>
                </div>
            </section>

            <section class="card">
                <div class="card__head">
                    <h2>Media</h2>
                    <span class="spacer"></span>
                    <a class="btn btn--sm" href="/media"><?= Ui::icon('plus', 15) ?> Upload</a>
                </div>
                <div class="card__body">
                    <input type="hidden" name="media_id" value="<?= (int) ($prefill['media_id'] ?? 0) ?>">
                    <div id="media-preview" class="mb-2"></div>

                    <?php if ($media === []): ?>
                        <?= Ui::emptyState('No media uploaded yet', 'Text-only posts work fine. Upload an image or video to attach one.', 'media', '/media', 'Go to media') ?>
                    <?php else: ?>
                        <div class="media-grid">
                            <?php foreach ($media as $item): ?>
                                <?php
                                $selected = (int) ($prefill['media_id'] ?? 0) === (int) $item['id'];
                                $preview = (string) $item['kind'] === 'IMAGE'
                                    ? '<img src="/media/' . (int) $item['id'] . '/file" style="max-width:100%;border-radius:8px">'
                                    : '<div class="small muted">' . Support::e((string) $item['original_name']) . ' · '
                                      . Support::e(Support::bytesToHuman((int) $item['size_bytes'])) . '</div>';
                                ?>
                                <article class="media-card<?= $selected ? ' is-selected' : '' ?>"
                                         data-media-pick="media-field-<?= (int) $item['id'] ?>"
                                         data-media-id="<?= (int) $item['id'] ?>"
                                         data-media-preview="<?= Support::e($preview) ?>">
                                    <div class="media-thumb" style="display:grid;place-items:center;color:#64748b">
                                        <?= Ui::icon($item['kind'] === 'VIDEO' ? 'video' : 'image', 26) ?>
                                    </div>
                                    <div class="media-card__body">
                                        <div class="media-card__name"><?= Support::e((string) $item['original_name']) ?></div>
                                        <p class="tiny muted mb-0">
                                            <?= Support::e(Support::bytesToHuman((int) $item['size_bytes'])) ?>
                                            <?php if ($item['duration_s'] !== null): ?> · <?= Support::e(Ui::duration((float) $item['duration_s'])) ?><?php endif; ?>
                                        </p>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                        <p class="field__hint mt-1">Click a file to attach it. Click again to keep it attached; use
                            <em>Clear</em> below to send a text-only post.</p>
                        <button class="btn btn--sm" type="button"
                                onclick="document.querySelectorAll('input[name=media_id]').forEach(function(f){f.value=0;});
                                         document.getElementById('media-preview').innerHTML='';
                                         document.querySelectorAll('.media-card.is-selected').forEach(function(c){c.classList.remove('is-selected');});">
                            Clear selection
                        </button>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card">
                <div class="card__head">
                    <h2>Pages</h2>
                    <span class="spacer"></span>
                    <span class="small muted"><?= $totalAvailable ?> available</span>
                </div>
                <div class="card__body">
                    <?php if ($totalAvailable === 0): ?>
                        <?= Ui::emptyState('No Pages available', 'Connect a Facebook account first — Pages are discovered after you sign in on your PC.', 'pages', '/accounts', 'Connect account') ?>
                    <?php else: ?>
                        <label class="check">
                            <input type="checkbox" data-check-all="#page-list">
                            <span class="check__text"><strong>Select all Pages</strong>
                                <span class="check__meta">Applies to every account below.</span></span>
                        </label>
                        <div class="page-picker" id="page-list">
                            <?php foreach ($accounts as $account): ?>
                                <?php if ($account['pages'] === []) { continue; } ?>
                                <div class="page-picker__group">
                                    <?= Support::e((string) $account['label']) ?>
                                    <?= Ui::status((string) $account['status']) ?>
                                </div>
                                <?php foreach ($account['pages'] as $page): ?>
                                    <?php $blocked = in_array((string) $page['status'], ['DISABLED', 'AUTH_REQUIRED', 'CHALLENGE_REQUIRED', 'ERROR'], true); ?>
                                    <label class="check">
                                        <input type="checkbox" name="page_ids[]" value="<?= (int) $page['id'] ?>"
                                            <?= $blocked ? 'disabled' : '' ?>
                                            <?= ((int) ($prefill['page_id'] ?? 0) === (int) $page['id']) ? 'checked' : '' ?>>
                                        <span class="check__text">
                                            <?= Support::e((string) $page['page_name']) ?>
                                            <?php if ($blocked): ?>
                                                <span class="check__meta">Skipped — <?= Support::e(strtolower(Ui::humanize((string) $page['status']))) ?></span>
                                            <?php endif; ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <div class="stack">
            <section class="card">
                <div class="card__head"><h2>Publish</h2></div>
                <div class="card__body">
                    <div class="field">
                        <label class="field__label" for="stagger_seconds">Spacing between Pages</label>
                        <select id="stagger_seconds" name="stagger_seconds">
                            <option value="0">Publish all at once</option>
                            <option value="30">30 seconds apart</option>
                            <option value="60">1 minute apart</option>
                            <option value="120">2 minutes apart</option>
                            <option value="300">5 minutes apart</option>
                        </select>
                        <p class="field__hint">Sequential spacing is kinder to your accounts when fanning out to many Pages.</p>
                    </div>

                    <label class="check">
                        <input type="checkbox" name="notify_on_complete" value="1" checked>
                        <span class="check__text">Notify me when each Page finishes</span>
                    </label>

                    <hr class="hr">

                    <div class="stack">
                        <button class="btn btn--primary btn--block" type="submit" name="intent" value="publish"
                                <?= $totalAvailable === 0 ? 'disabled' : '' ?>>
                            <?= Ui::icon('play', 16) ?> Publish now
                        </button>

                        <button class="btn btn--block" type="submit" name="intent" value="schedule"
                                <?= $totalAvailable === 0 ? 'disabled' : '' ?>
                                onclick="document.getElementById('schedule-panel').hidden=false;">
                            <?= Ui::icon('clock', 16) ?> Schedule
                        </button>

                        <button class="btn btn--block" type="submit" name="intent" value="draft">
                            <?= Ui::icon('posts', 16) ?> Save as draft
                        </button>
                    </div>
                </div>
            </section>

            <section class="card" id="schedule-panel" hidden>
                <div class="card__head"><h2>Schedule</h2></div>
                <div class="card__body">
                    <div class="field">
                        <label class="field__label" for="scheduled_at_local">Date and time</label>
                        <input id="scheduled_at_local" name="scheduled_at_local" type="datetime-local"
                               value="<?= Support::e(Clock::now()->modify('+1 hour')->format('Y-m-d\TH:i')) ?>">
                        <p class="field__hint">Stored in UTC, shown in <?= Support::e($timezone) ?>.</p>
                    </div>

                    <div class="field">
                        <label class="field__label" for="recurrence">Repeat</label>
                        <select id="recurrence" name="recurrence">
                            <option value="NONE">Once</option>
                            <option value="EVERY_X_MINUTES">Every X minutes</option>
                            <option value="EVERY_X_HOURS">Every X hours</option>
                        </select>
                    </div>

                    <div class="form-row">
                        <div class="field">
                            <label class="field__label" for="interval_value">Interval</label>
                            <input id="interval_value" name="interval_value" type="number" min="1" max="10080" value="1">
                        </div>
                        <div class="field">
                            <label class="field__label" for="max_runs">Maximum runs</label>
                            <input id="max_runs" name="max_runs" type="number" min="0" max="1000" value="0"
                                   placeholder="0 = unlimited">
                        </div>
                    </div>

                    <div class="alert alert--info">
                        <?= Ui::icon('shield', 16) ?>
                        <div class="small">
                            Scheduling spaces your own authorised content. Don't use it to mass-post repetitive
                            material — that is exactly the behaviour platform rules exist to prevent.
                        </div>
                    </div>
                </div>
            </section>

            <section class="card">
                <div class="card__head"><h2>What happens next</h2></div>
                <div class="card__body small muted">
                    <ol style="margin:0;padding-left:18px">
                        <li>PHP stores the post and creates one job per Page.</li>
                        <li>Your Windows worker claims the job when it is due.</li>
                        <li>A browser profile for the account opens the Page.</li>
                        <li>The media uploads, the caption is entered, Publish is pressed.</li>
                        <li>The result is verified on the Page, then reported back here.</li>
                    </ol>
                    <p class="mt-1 mb-0">If Facebook asks for a verification, the job pauses and you are notified.</p>
                </div>
            </section>
        </div>
    </div>
</form>
