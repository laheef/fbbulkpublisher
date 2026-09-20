<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Logger;

/**
 * Media inspection.
 *
 * Images are always inspected in-process (getimagesize). Video inspection uses
 * ffprobe when one is available on the server; otherwise the file is marked
 * PENDING and the Windows worker — which always ships a bundled FFmpeg —
 * reports the metadata back via POST /worker/media/{id}/probe.
 */
final class MediaProbe
{
    /** @return array{ok:bool,width:?int,height:?int,duration_s:?float,fps:?float,codec:?string,aspect_ratio:?string,error:?string} */
    public static function inspect(string $path, string $kind, string $mime): array
    {
        $result = [
            'ok' => false, 'width' => null, 'height' => null, 'duration_s' => null,
            'fps' => null, 'codec' => null, 'aspect_ratio' => null, 'error' => null,
        ];

        if ($kind === 'IMAGE') {
            $info = @getimagesize($path);
            if (is_array($info)) {
                $result['width'] = (int) $info[0];
                $result['height'] = (int) $info[1];
                $result['aspect_ratio'] = self::aspectRatio((int) $info[0], (int) $info[1]);
                $result['ok'] = true;
            } else {
                $result['error'] = 'The image could not be read.';
            }
            return $result;
        }

        $probe = self::ffprobe($path);
        if ($probe === null) {
            $result['error'] = 'Awaiting FFmpeg inspection on the Windows worker.';
            return $result;
        }

        return $probe;
    }

    /** @return array{ok:bool,width:?int,height:?int,duration_s:?float,fps:?float,codec:?string,aspect_ratio:?string,error:?string}|null */
    public static function ffprobe(string $path): ?array
    {
        $binary = self::ffprobeBinary();
        if ($binary === null) {
            return null;
        }

        $cmd = escapeshellcmd($binary) . ' -v quiet -print_format json -show_format -show_streams '
            . escapeshellarg($path) . ' 2>&1';

        $output = shell_exec($cmd);
        if (!is_string($output) || $output === '') {
            Logger::warn('ffprobe produced no output', ['path' => basename($path)]);
            return null;
        }

        $data = json_decode($output, true);
        if (!is_array($data)) {
            // ffprobe can emit several concatenated JSON objects; take the first.
            $firstLine = trim(strtok($output, "\n") ?: '');
            $data = json_decode($firstLine, true);
            if (!is_array($data)) {
                return null;
            }
        }

        $videoStream = null;
        foreach ((array) ($data['streams'] ?? []) as $stream) {
            if (($stream['codec_type'] ?? '') === 'video') {
                $videoStream = $stream;
                break;
            }
        }

        if ($videoStream === null) {
            return [
                'ok' => false, 'width' => null, 'height' => null, 'duration_s' => null, 'fps' => null,
                'codec' => null, 'aspect_ratio' => null, 'error' => 'No video stream was found in this file.',
            ];
        }

        $width = isset($videoStream['width']) ? (int) $videoStream['width'] : null;
        $height = isset($videoStream['height']) ? (int) $videoStream['height'] : null;
        $duration = isset($data['format']['duration']) ? (float) $data['format']['duration'] : null;

        $fps = null;
        if (isset($videoStream['avg_frame_rate']) && str_contains((string) $videoStream['avg_frame_rate'], '/')) {
            [$num, $den] = array_map('floatval', explode('/', (string) $videoStream['avg_frame_rate'], 2));
            if ($den > 0) {
                $fps = round($num / $den, 3);
            }
        }

        return [
            'ok'           => true,
            'width'        => $width,
            'height'       => $height,
            'duration_s'   => $duration,
            'fps'          => $fps,
            'codec'        => isset($videoStream['codec_name']) ? (string) $videoStream['codec_name'] : null,
            'aspect_ratio' => ($width !== null && $height !== null) ? self::aspectRatio($width, $height) : null,
            'error'        => null,
        ];
    }

    /** Generate a JPEG poster frame next to the media. Returns a relative path. */
    public static function generateThumbnail(string $path, string $kind, string $relativePath): ?string
    {
        if ($kind === 'IMAGE') {
            return $relativePath;   // browsers can render the image itself
        }

        $ffmpeg = self::ffmpegBinary();
        if ($ffmpeg === null) {
            return null;   // the worker will generate one with its bundled FFmpeg
        }

        $thumbRelative = preg_replace('/\.[A-Za-z0-9]+$/', '', $relativePath) . '_thumb.jpg';
        $thumbsRoot = rtrim(Config::str('uploads.disk_path'), '/') . '/thumbs/';
        $thumbAbsolute = $thumbsRoot . str_replace('/', '_', (string) $thumbRelative);

        if (!is_dir($thumbsRoot) && !mkdir($thumbsRoot, 0775, true) && !is_dir($thumbsRoot)) {
            return null;
        }

        $cmd = escapeshellcmd($ffmpeg) . ' -y -ss 00:00:01 -i ' . escapeshellarg($path)
            . ' -frames:v 1 -vf scale=640:-2 ' . escapeshellarg($thumbAbsolute) . ' 2>&1';

        @shell_exec($cmd);

        if (is_file($thumbAbsolute) && filesize($thumbAbsolute) > 0) {
            return 'thumbs/' . str_replace('/', '_', (string) $thumbRelative);
        }

        return null;
    }

    public static function ffprobeBinary(): ?string
    {
        return self::locate((string) Config::get('ffprobe.binary', '') ?: 'ffprobe');
    }

    public static function ffmpegBinary(): ?string
    {
        return self::locate((string) Config::get('ffmpeg.binary', '') ?: 'ffmpeg');
    }

    public static function version(string $binary): ?string
    {
        $path = self::locate($binary);
        if ($path === null) {
            return null;
        }
        $out = shell_exec(escapeshellcmd($path) . ' -version 2>&1 | head -n 1');
        return is_string($out) ? trim($out) : null;
    }

    public static function aspectRatio(int $width, int $height): string
    {
        if ($width <= 0 || $height <= 0) {
            return 'unknown';
        }
        $gcd = static function (int $a, int $b) use (&$gcd): int {
            return $b === 0 ? $a : $gcd($b, $a % $b);
        };
        $divisor = $gcd($width, $height);
        $w = intdiv($width, $divisor);
        $h = intdiv($height, $divisor);
        return ($w > 30 || $h > 30) ? round($width / $height, 3) . ':1' : "{$w}:{$h}";
    }

    private static function locate(string $binary): ?string
    {
        if ($binary === '') {
            return null;
        }
        if (str_contains($binary, '/') || str_contains($binary, '\\')) {
            return is_file($binary) && is_executable($binary) ? $binary : null;
        }
        $found = shell_exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null');
        $path = is_string($found) ? trim($found) : '';
        return $path !== '' && is_file($path) ? $path : null;
    }
}
