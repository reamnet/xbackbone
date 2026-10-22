<?php

namespace App\Services;

use App\Storage\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;

class VideoPreviews
{
    public static function path(object $media): string
    {
        return BASE_DIR.'resources/previews/'.hash('sha256', ($media->mediaId ?? $media->id).':'.$media->storage_path).'.jpg';
    }

    public function generate(object $media, Filesystem $storage, array $config): void
    {
        if (!str_starts_with($storage->getMimetype($media->storage_path), 'video/')) {
            return;
        }
        $target = self::path($media);
        if (is_file($target)) {
            return;
        }
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0770, true) && !is_dir(dirname($target))) {
            throw new RuntimeException('Cannot create preview directory.');
        }
        $temporary = null;
        $log = null;
        try {
            if ($storage->getAdapter() instanceof LocalFilesystemAdapter) {
                $source = rtrim($config['storage']['path'], '/').'/'.$media->storage_path;
            } else {
                $source = $temporary = tempnam(sys_get_temp_dir(), 'xbb-video-');
                $input = $output = null;
                try {
                    $input = $storage->readStream($media->storage_path);
                    $output = fopen($source, 'wb');
                    if (!is_resource($output) || stream_copy_to_stream($input, $output) === false) {
                        throw new RuntimeException('Cannot materialize video.');
                    }
                } finally {
                    if (is_resource($input)) {
                        fclose($input);
                    }
                    if (is_resource($output)) {
                        fclose($output);
                    }
                }
            }
            $log = tempnam(sys_get_temp_dir(), 'xbb-ffmpeg-');
            $process = proc_open([
                getenv('FFMPEG_PATH') ?: 'ffmpeg', '-nostdin', '-v', 'error', '-y',
                '-protocol_whitelist', 'file,pipe', '-i', $source, '-frames:v', '1',
                '-vf', 'scale=600:338:force_original_aspect_ratio=decrease,thumbnail=30',
                '-f', 'image2', $target.'.tmp.jpg',
            ], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $log, 'w']], $pipes);
            if (!is_resource($process)) {
                throw new RuntimeException('FFmpeg is unavailable.');
            }
            $deadline = microtime(true) + 120;
            do {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
                if (microtime(true) > $deadline) {
                    proc_terminate($process, 9);
                    proc_close($process);
                    throw new RuntimeException('Video preview timed out.');
                }
                usleep(100000);
            } while (true);
            proc_close($process);
            if ($status['exitcode'] !== 0 || !is_file($target.'.tmp.jpg') || !filesize($target.'.tmp.jpg') || !rename($target.'.tmp.jpg', $target)) {
                throw new RuntimeException('Video preview generation failed.');
            }
        } finally {
            if ($log) {
                @unlink($log);
            }
            @unlink($target.'.tmp.jpg');
            if ($temporary) {
                @unlink($temporary);
            }
        }
    }
}
