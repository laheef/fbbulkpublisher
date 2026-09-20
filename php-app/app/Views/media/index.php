<?php
declare(strict_types=1);
use App\Core\Support;
use App\Core\Ui;

/** @var list<array> $media @var array $summary @var array $limits @var string|null $ffmpeg */
?>
<div class="page-head">
    <div class="page-head__text">
        <h1>Media</h1>
        <p class="page-head__sub">
            <?= (int) $summary['images'] ?> images · <?= (int) $summary['videos'] ?> videos ·
            <?= Support::e(Support::bytesToHuman((int) $summary['bytes'])) ?> stored
        </p>
    </div>
    <div class="page-head__actions">
        <label class="btn btn--primary" for="file-input"><?= Ui::icon('plus', 16) ?> Upload media</label>
    </div>
</div>

<section class="card mb-2">
    <div class="card__body">
        <form id="upload-form" method="post" action="/media/upload" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?= Support::e($csrfToken) ?>">
            <div class="field">
                <input id="file-input" name="file" type="file"
                       accept=".jpg,.jpeg,.png,.webp,.mp4,.mov,image/jpeg,image/png,image/webp,video/mp4,video/quicktime">
                <p class="field__hint">
                    Images up to <?= Support::e(Support::bytesToHuman((int) $limits['image'])) ?> ·
                    Videos up to <?= Support::e(Support::bytesToHuman((int) $limits['video'])) ?> ·
                    JPG, JPEG, PNG, WEBP, MP4 and MOV.
                </p>
            </div>
            <button class="btn btn--primary" type="submit"><?= Ui::icon('download', 16) ?> Upload</button>
        </form>

        <div class="alert alert--info mt-2">
            <?= Ui::icon('shield', 16) ?>
            <div class="small">
                Uploads are validated by sniffing the real file type, then stored outside the web root.
                <?php if ($ffmpeg === null): ?>
                    Video metadata and poster frames are produced by the <strong>FFmpeg bundled with your Windows app</strong>,
                    so they appear a moment after your machine picks the file up.
                <?php else: ?>
                    Server-side FFmpeg detected: <span class="mono"><?= Support::e(Support::truncate($ffmpeg, 60)) ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<?php if ($media === []): ?>
    <div class="card"><?= Ui::emptyState('No media yet', 'Upload an image or video, then attach it while composing a post.', 'media') ?></div>
<?php else: ?>
    <div class="media-grid">
        <?php foreach ($media as $item): ?>
            <article class="media-card">
                <?php if ((string) $item['kind'] === 'IMAGE'): ?>
                    <img class="media-thumb" src="/media/<?= (int) $item['id'] ?>/file" alt="" loading="lazy">
                <?php else: ?>
                    <div class="media-thumb" style="display:grid;place-items:center;color:#64748b">
                        <?= Ui::icon('video', 30) ?>
                    </div>
                <?php endif; ?>

                <div class="media-card__body">
                    <div class="media-card__name" title="<?= Support::e((string) $item['original_name']) ?>">
                        <?= Support::e((string) $item['original_name']) ?>
                    </div>
                    <p class="tiny muted mb-1">
                        <?= Support::e(Support::bytesToHuman((int) $item['size_bytes'])) ?>
                        <?php if ($item['width'] !== null && $item['height'] !== null): ?>
                            · <?= (int) $item['width'] ?>×<?= (int) $item['height'] ?>
                        <?php endif; ?>
                        <?php if ($item['duration_s'] !== null): ?>
                            · <?= Support::e(Ui::duration((float) $item['duration_s'])) ?>
                        <?php endif; ?>
                        <?php if (!empty($item['fps'])): ?>
                            · <?= Support::e(rtrim(rtrim(number_format((float) $item['fps'], 2), '0'), '.')) ?> fps
                        <?php endif; ?>
                    </p>
                    <div class="row" style="gap:6px">
                        <?= Ui::status((string) $item['probe_status']) ?>
                        <?php if (!empty($item['codec'])): ?><span class="pill pill--muted"><?= Support::e((string) $item['codec']) ?></span><?php endif; ?>
                    </div>
                    <div class="row mt-1" style="gap:6px">
                        <a class="btn btn--sm" href="/media/<?= (int) $item['id'] ?>">Details</a>
                        <a class="btn btn--sm" href="/posts/new?media=<?= (int) $item['id'] ?>&kind=<?= $item['kind'] === 'VIDEO' ? 'VIDEO' : 'IMAGE' ?>">Use</a>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
