<?php

declare(strict_types=1);

/**
 * Upload security: content sniffing beats the file extension, stored names are
 * regenerated, and media that a scheduled job still needs is never removed.
 */

use App\Core\Clock;
use App\Core\Config;
use App\Core\HttpException;
use App\Models\Job;
use App\Models\Media;
use App\Models\Post;
use App\Services\MediaService;

function temp_upload(string $contents, string $clientName, string $clientType): array
{
    $path = tempnam(sys_get_temp_dir(), 'lkupload');
    file_put_contents($path, $contents);

    return [
        'name'     => $clientName,
        'type'     => $clientType,
        'tmp_name' => $path,
        'error'    => UPLOAD_ERR_OK,
        'size'     => filesize($path),
    ];
}

function png_bytes(int $width = 8, int $height = 8): string
{
    $raw = '';
    for ($y = 0; $y < $height; $y++) {
        $raw .= "\x00";
        for ($x = 0; $x < $width; $x++) {
            $raw .= chr(($x * 20) % 256) . chr(120) . chr(200);
        }
    }

    $chunk = static function (string $type, string $data): string {
        $body = $type . $data;
        return pack('N', strlen($data)) . $body . pack('N', crc32($body));
    };

    return "\x89PNG\r\n\x1a\n"
        . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
        . $chunk('IDAT', gzcompress($raw))
        . $chunk('IEND', '');
}

test('a real image uploads and gets clean metadata', function (): void {
    $ctx = seed_workspace();
    $media = MediaService::store((int) $ctx['user_id'], temp_upload(png_bytes(), 'launch.png', 'image/png'));

    assert_same('IMAGE', $media['kind']);
    assert_same('image/png', $media['mime_type']);
    assert_true(!str_contains((string) $media['relative_path'], 'launch'), 'the stored name is regenerated, not taken from the client');
    assert_same(1, preg_match('#^\d{4}/\d{2}/[a-f0-9]{32,}\.[a-z0-9]+$#', (string) $media['relative_path']), 'stored paths are dated and hashed');

    $absolute = Media::absolutePath($media);
    assert_true(is_file($absolute), 'the file exists on disk');
    assert_same(0640, fileperms($absolute) & 0777, 'stored media is not world readable');

    $row = Media::find((int) $media['id']);
    assert_true($row !== null);
    assert_same((int) $ctx['user_id'], (int) $row['user_id'], 'the upload belongs to its workspace');
});

test('a PHP payload renamed to .png is rejected', function (): void {
    $ctx = seed_workspace();
    $upload = temp_upload('<?php system($_GET["c"]); ?>', 'innocent.png', 'image/png');

    assert_throws(HttpException::class, fn () => MediaService::store((int) $ctx['user_id'], $upload),
        'a script wearing an image extension must not be stored');

    assert_same(0, (int) db()->scalar('SELECT COUNT(*) FROM media', []), 'and must not leave a row behind');
});

test('the sniffing helper identifies the real type, not the extension', function (): void {
    $png = tempnam(sys_get_temp_dir(), 'lk');
    file_put_contents($png, png_bytes());
    assert_same('image/png', MediaService::sniffMime($png));

    $text = tempnam(sys_get_temp_dir(), 'lk');
    file_put_contents($text, 'plain text pretending to be a jpeg');
    $sniffed = MediaService::sniffMime($text);
    assert_true(!str_contains($sniffed, 'image/'), "plain text was sniffed as {$sniffed}");
});

test('an oversized file is rejected', function (): void {
    $ctx = seed_workspace();
    $limit = (int) Config::int('uploads.max_image_bytes', 20 * 1024 * 1024);

    // Build a file that claims to be larger than the cap without allocating it.
    $path = tempnam(sys_get_temp_dir(), 'lkbig');
    file_put_contents($path, png_bytes());
    $handle = fopen($path, 'r+');
    ftruncate($handle, $limit + 1);
    fclose($handle);

    $upload = [
        'name' => 'huge.png', 'type' => 'image/png', 'tmp_name' => $path,
        'error' => UPLOAD_ERR_OK, 'size' => $limit + 1,
    ];

    assert_throws(HttpException::class, fn () => MediaService::store((int) $ctx['user_id'], $upload));
});

test('media referenced by a scheduled job is never pruned', function (): void {
    $ctx = seed_workspace();
    $media = MediaService::store((int) $ctx['user_id'], temp_upload(png_bytes(), 'used.png', 'image/png'));

    $post = Post::compose((int) $ctx['user_id'], [
        'caption'  => 'Uses this image',
        'kind'     => 'IMAGE',
        'media_id' => (int) $media['id'],
        'settings' => [],
    ], 'Asia/Karachi');

    Job::enqueue([
        'user_id'      => (int) $ctx['user_id'],
        'post_id'      => (int) $post['id'],
        'page_id'      => (int) $ctx['page_ids'][0],
        'account_id'   => (int) $ctx['account_id'],
        'worker_id'    => (int) $ctx['worker']['id'],
        'job_type'     => 'PUBLISH_IMAGE',
        'scheduled_at' => Clock::now()->modify('+1 day')->format('Y-m-d H:i:s'),
    ], 0, 'media-salt');

    assert_true(Media::isReferenced((int) $media['id']), 'a scheduled job keeps the media referenced');

    $absolute = Media::absolutePath($media);
    $removed = MediaService::pruneOrphanedFiles(200);
    assert_same(0, $removed, 'pruning must not touch referenced media');
    assert_true(is_file($absolute), 'the referenced file is still on disk');

    // A draft or queued post still protects its media, even with the job gone.
    db()->query('DELETE FROM jobs WHERE post_id = ?', [(int) $post['id']]);
    assert_true(Media::isReferenced((int) $media['id']), 'a post that still points at the media keeps it protected');

    // Only when nothing references it any more may it be pruned.
    db()->query('DELETE FROM posts WHERE id = ?', [(int) $post['id']]);
    db()->query('DELETE FROM post_pages WHERE post_id = ?', [(int) $post['id']]);
    assert_true(!Media::isReferenced((int) $media['id']), 'with no job and no post left, the media is unreferenced');
});

test('an unreferenced, deleted file is cleaned up by pruning', function (): void {
    $ctx = seed_workspace();
    $media = MediaService::store((int) $ctx['user_id'], temp_upload(png_bytes(), 'orphan.png', 'image/png'));
    $absolute = Media::absolutePath($media);

    Media::softDelete((int) $media['id']);
    $row = Media::find((int) $media['id']);
    assert_true($row['deleted_at'] !== null, 'soft delete marks the row');
    assert_true(is_file($absolute), 'the file survives until retention pruning decides');

    $removed = MediaService::pruneOrphanedFiles(200);
    assert_true($removed >= 1, 'an unreferenced, soft-deleted file is pruned');
    assert_true(!is_file($absolute), 'the file is gone from disk');
});

test('one workspace cannot stream another workspace\'s media', function (): void {
    $first = seed_workspace(['email' => 'owner@example.test']);
    $second = seed_workspace(['email' => 'stranger@example.test', 'worker_name' => 'OTHER-PC']);

    $media = MediaService::store((int) $first['user_id'], temp_upload(png_bytes(), 'private.png', 'image/png'));

    assert_same(0, count(Media::forUser((int) $second['user_id'])), 'the second workspace sees no media');
    assert_same(1, count(Media::forUser((int) $first['user_id'])), 'the owner sees their own media');
});
