<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\Media;
use App\Services\MediaProbe;
use App\Services\MediaService;

final class MediaController extends Controller
{
    public function index(Request $request): Response
    {
        $userId = $this->requireUserId();

        return $this->view('media/index', [
            'title'    => 'Media',
            'media'    => Media::forUser($userId, 200),
            'summary'  => Media::summaryForUser($userId),
            'limits'   => [
                'image' => Config::int('uploads.max_image_bytes', 26214400),
                'video' => Config::int('uploads.max_video_bytes', 4294967296),
            ],
            'ffmpeg'   => MediaProbe::version('ffmpeg'),
        ]);
    }

    public function upload(Request $request): Response
    {
        $userId = $this->requireUserId();

        $file = $_FILES['file'] ?? null;
        if (!is_array($file)) {
            throw HttpException::validation('Choose a file to upload.', ['file' => 'No file was received.']);
        }

        $media = MediaService::store($userId, $file);
        $this->log('media.uploaded', 'media', (int) $media['id'], [
            'kind' => $media['kind'], 'bytes' => (int) $media['size_bytes'],
        ]);

        if ($request->expectsJson()) {
            return $this->json(['media' => self::present($media)]);
        }

        return $this->redirect('/media');
    }

    /** Chunked upload endpoint for very large videos (§53). */
    public function uploadChunk(Request $request): Response
    {
        $userId = $this->requireUserId();
        $uploadId = preg_replace('/[^A-Za-z0-9_-]/', '', $request->string('upload_id')) ?? '';
        $chunkIndex = $request->int('chunk_index', 0);
        $chunkTotal = max(1, $request->int('chunk_total', 1));
        $originalName = \App\Core\Support::safeFileName($request->string('filename', 'upload.bin'));

        if ($uploadId === '' || strlen($uploadId) < 8) {
            throw HttpException::validation('A valid upload_id is required.');
        }
        if ($chunkIndex < 0 || $chunkIndex >= $chunkTotal) {
            throw HttpException::validation('Invalid chunk index.');
        }

        $tmpRoot = sys_get_temp_dir() . '/linkeasy-chunks';
        $dir = $tmpRoot . '/' . $userId . '_' . $uploadId;
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to prepare the upload buffer.');
        }

        $file = $_FILES['chunk'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
            throw HttpException::validation('A chunk failed to arrive. Please retry.');
        }

        $chunkPath = $dir . '/' . str_pad((string) $chunkIndex, 6, '0', STR_PAD_LEFT) . '.part';
        if (!move_uploaded_file((string) $file['tmp_name'], $chunkPath)) {
            if (!@rename((string) $file['tmp_name'], $chunkPath)) {
                throw new \RuntimeException('Unable to persist the uploaded chunk.');
            }
        }

        $received = count(glob($dir . '/*.part') ?: []);
        if ($received < $chunkTotal) {
            return $this->json(['received' => $received, 'total' => $chunkTotal, 'complete' => false]);
        }

        // Assemble in order.
        $assembled = $dir . '/assembled.bin';
        $out = fopen($assembled, 'wb');
        if ($out === false) {
            throw new \RuntimeException('Unable to assemble the upload.');
        }
        for ($i = 0; $i < $chunkTotal; $i++) {
            $part = $dir . '/' . str_pad((string) $i, 6, '0', STR_PAD_LEFT) . '.part';
            if (!is_file($part)) {
                fclose($out);
                throw new HttpException(409, 'A chunk is missing; please restart this upload.');
            }
            $in = fopen($part, 'rb');
            if ($in !== false) {
                stream_copy_to_stream($in, $out);
                fclose($in);
            }
            @unlink($part);
        }
        fclose($out);

        $media = MediaService::store($userId, [
            'name'     => $originalName,
            'tmp_name' => $assembled,
            'size'     => (int) filesize($assembled),
            'error'    => UPLOAD_ERR_OK,
        ]);

        // Clean the buffer directory.
        foreach (glob($dir . '/*') ?: [] as $leftover) {
            @unlink($leftover);
        }
        @rmdir($dir);

        $this->log('media.uploaded', 'media', (int) $media['id'], ['chunked' => true, 'chunks' => $chunkTotal]);

        return $this->json(['complete' => true, 'media' => self::present($media)]);
    }

    public function show(Request $request, array $params): Response
    {
        $this->requireUserId();
        $mediaId = $this->paramInt($params, 'id', 'media');
        $media = $this->owned(Media::find($mediaId), 'media');

        return $this->view('media/show', [
            'title'      => $media['original_name'],
            'media_row'  => $media,
            'referenced' => Media::isReferenced($mediaId),
            'posts'      => \App\Core\Database::instance()->select(
                'SELECT id, uuid, kind, status, caption, created_at FROM posts WHERE media_id = ? ORDER BY created_at DESC LIMIT 50',
                [$mediaId]
            ),
            'thumbnail'  => Media::thumbnailAbsolutePath($media) !== null,
        ]);
    }

    /** Stream the raw file to the signed-in owner (also used by the worker). */
    public function file(Request $request, array $params): Response
    {
        $this->requireUserId();
        $mediaId = $this->paramInt($params, 'id', 'media');
        $media = $this->owned(Media::find($mediaId), 'media');

        MediaService::stream($media);
        exit;   // streaming writes the response directly
    }

    public function thumbnail(Request $request, array $params): Response
    {
        $this->requireUserId();
        $mediaId = $this->paramInt($params, 'id', 'media');
        $media = $this->owned(Media::find($mediaId), 'media');

        $path = Media::thumbnailAbsolutePath($media);
        if ($path === null) {
            throw HttpException::notFound('No thumbnail has been generated for this file yet.');
        }

        return Response::html((string) file_get_contents($path))
            ->withHeader('Content-Type', 'image/jpeg')
            ->withHeader('Cache-Control', 'private, max-age=3600');
    }

    public function remove(Request $request, array $params): Response
    {
        $this->requireUserId();
        $mediaId = $this->paramInt($params, 'id', 'media');
        $media = $this->owned(Media::find($mediaId), 'media');

        if (Media::isReferenced($mediaId)) {
            throw HttpException::conflict(
                'This file is still attached to a live post or job. Finish or cancel that work first.',
                ['referenced' => '1']
            );
        }

        Media::softDelete($mediaId);
        $this->log('media.deleted', 'media', $mediaId);

        if ($request->expectsJson()) {
            return $this->json(['deleted' => $mediaId]);
        }

        return $this->redirect('/media');
    }

    /** @return array<string,mixed> */
    private static function present(array $media): array
    {
        return [
            'id'            => (int) $media['id'],
            'kind'          => $media['kind'],
            'original_name' => $media['original_name'],
            'mime_type'     => $media['mime_type'],
            'size_bytes'    => (int) $media['size_bytes'],
            'size_human'    => \App\Core\Support::bytesToHuman((int) $media['size_bytes']),
            'width'         => $media['width'] !== null ? (int) $media['width'] : null,
            'height'        => $media['height'] !== null ? (int) $media['height'] : null,
            'duration_s'    => $media['duration_s'] !== null ? (float) $media['duration_s'] : null,
            'fps'           => $media['fps'] !== null ? (float) $media['fps'] : null,
            'codec'         => $media['codec'],
            'aspect_ratio'  => $media['aspect_ratio'],
            'probe_status'  => $media['probe_status'],
            'thumbnail_url' => '/media/' . (int) $media['id'] . '/thumbnail',
            'file_url'      => '/media/' . (int) $media['id'] . '/file',
        ];
    }
}
