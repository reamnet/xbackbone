<?php

namespace App\Services;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Psr7\UploadedFile;

class ChunkUploads
{
    public function __construct(private \App\Database\DB $db)
    {
    }
    public function receive(Request $request, Response $response, int $userId, callable $complete): Response
    {
        $id = param($request, 'dzuuid');
        $total = filter_var(param($request, 'dztotalfilesize'), FILTER_VALIDATE_INT);
        $offset = filter_var(param($request, 'dzchunkbyteoffset'), FILTER_VALIDATE_INT);
        $file = array_values($request->getUploadedFiles())[0] ?? null;
        $max = (int) (getenv('UPLOAD_MAX_BYTES') ?: 20 * 1024 ** 3);
        if (PHP_INT_SIZE < 8 || !is_string($id) || !preg_match('/^[a-z0-9-]{16,64}$/i', $id) || !$total || $total > $max || $offset === false || $offset < 0 || !$file || $file->getError() !== UPLOAD_ERR_OK || $file->getSize() < 1 || $file->getSize() > 8 * 1024 ** 2 || $offset + $file->getSize() > $total) {
            return json($response, ['message' => 'Invalid upload chunk.'], 422);
        }
        $root = BASE_DIR.'resources/chunks/'.$userId;
        if (!is_dir($root) && !mkdir($root, 0770, true) && !is_dir($root)) {
            return json($response, ['message' => 'Upload storage is unavailable.'], 507);
        }
        // Serialize this user's requests, including new upload reservations and finalization.
        $lock = fopen($root.'/.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) {
            return json($response, ['message' => 'Unable to lock upload storage.'], 503);
        }
        $part = $root.'/'.$id.'.part';
        $metaPath = $root.'/'.$id.'.json';
        try {
            if (!is_file($metaPath)) {
                $pending = glob($root.'/*.json');
                $reserved = 0;
                $active = 0;
                foreach ($pending as $path) {
                    $meta = json_decode(file_get_contents($path), true);
                    if (filemtime($path) < time() - 86400) {
                        @unlink(substr($path, 0, -5).'.part');
                        @unlink($path);
                    } elseif (!isset($meta['response'])) {
                        $reserved += $meta['total'] ?? 0;
                        $active++;
                    }
                }
                if ($active >= 10 || $reserved + $total > 2 * $max) {
                    return json($response, ['message' => 'Too many unfinished uploads. Wait for cleanup or finish existing uploads.'], 429);
                }
                $user = $this->db->query('SELECT * FROM users WHERE id=?', $userId)->fetch();
                $quota = $this->db->query("SELECT value FROM settings WHERE `key`='quota_enabled'")->fetch();
                if (!$user || !$user->active || (($quota->value ?? '') === 'on' && $user->max_disk_quota > 0 && $user->current_disk_quota + $reserved + $total > $user->max_disk_quota)) {
                    return json($response, ['message' => 'Storage quota exceeded.'], 507);
                }
                $meta = ['total' => $total, 'name' => $file->getClientFilename(), 'received' => 0];
                $this->writeMeta($metaPath, $meta);
            } else {
                $meta = json_decode(file_get_contents($metaPath), true, flags: JSON_THROW_ON_ERROR);
            }
            if ($meta['total'] !== $total || $meta['name'] !== $file->getClientFilename()) {
                return json($response, ['message' => 'Upload identity mismatch.'], 409);
            }
            if (isset($meta['response'])) {
                return json($response, $meta['response'], 201);
            }
            if ($meta['received'] === $total) {
                $result = $this->complete($complete, $part, $meta['name'], $total, hash('sha256', $userId.':'.$id));
                if ($result->getStatusCode() === 201) {
                    $result->getBody()->rewind();
                    $meta['response'] = json_decode($result->getBody()->getContents(), true, flags: JSON_THROW_ON_ERROR);
                    $this->writeMeta($metaPath, $meta);
                    @unlink($part);
                    $result->getBody()->rewind();
                }
                return $result;
            }
            if ($offset !== $meta['received']) {
                // Dropzone may retry an acknowledged chunk after losing its HTTP response.
                if ($offset + $file->getSize() <= $meta['received']) {
                    return json($response, ['offset' => $meta['received']]);
                }
                return json($response, ['message' => 'Upload offset mismatch.', 'offset' => $meta['received']], 409);
            }
            $out = fopen($part, 'c+b');
            if (!$out) {
                throw new \RuntimeException('Cannot open upload storage.');
            }
            try {
                if (fstat($out)['size'] < $meta['received'] || !ftruncate($out, $meta['received']) || fseek($out, $meta['received']) !== 0) {
                    throw new \RuntimeException('Cannot seek upload storage.');
                }
                $stream = $file->getStream();
                $copied = 0;
                while (!$stream->eof()) {
                    $bytes = $stream->read(1024 * 1024);
                    $written = fwrite($out, $bytes);
                    if ($written !== strlen($bytes)) {
                        throw new \RuntimeException('Upload disk is full.');
                    }
                    $copied += $written;
                }
                if ($copied !== $file->getSize()) {
                    throw new \RuntimeException('Incomplete upload chunk.');
                }
                fflush($out);
            } finally {
                fclose($out);
            }
            $meta['received'] += $copied;
            $this->writeMeta($metaPath, $meta);
            if ($meta['received'] === $total) {
                $result = $this->complete($complete, $part, $meta['name'], $total, hash('sha256', $userId.':'.$id));
                if ($result->getStatusCode() === 201) {
                    $result->getBody()->rewind();
                    $meta['response'] = json_decode($result->getBody()->getContents(), true, flags: JSON_THROW_ON_ERROR);
                    $this->writeMeta($metaPath, $meta);
                    @unlink($part);
                    $result->getBody()->rewind();
                }
                return $result;
            }
            return json($response, ['offset' => $meta['received']]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function complete(callable $complete, string $part, string $name, int $total, string $key): Response
    {
        $pdo = $this->db->getPdo();
        $pdo->beginTransaction();
        try {
            $receipt = $this->db->query('SELECT response FROM upload_receipts WHERE receipt_key=?', $key)->fetch();
            if ($receipt) {
                $pdo->commit();
                return json(new \Slim\Psr7\Response(), json_decode($receipt->response, true, flags: JSON_THROW_ON_ERROR), 201);
            }
            $result = $complete(new UploadedFile($part, $name, null, $total));
            if ($result->getStatusCode() === 201) {
                $result->getBody()->rewind();
                $this->db->query('INSERT INTO upload_receipts(receipt_key,response) VALUES(?,?)', [$key, $result->getBody()->getContents()]);
                $result->getBody()->rewind();
                $pdo->commit();
            } else {
                $pdo->rollBack();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function writeMeta(string $path, array $meta): void
    {
        if (file_put_contents($path.'.new', json_encode($meta, JSON_THROW_ON_ERROR)) === false || !rename($path.'.new', $path)) {
            throw new \RuntimeException('Cannot save upload progress.');
        }
    }
}
