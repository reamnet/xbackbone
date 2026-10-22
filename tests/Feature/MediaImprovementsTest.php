<?php

namespace Tests\Feature;

use App\Database\Repositories\MediaRepository;
use App\Database\Repositories\TagRepository;
use App\Services\ChunkUploads;
use App\Services\SsoAccounts;
use GuzzleHttp\Psr7\ServerRequest;
use Slim\Psr7\Response;
use Slim\Psr7\UploadedFile;
use Tests\TestCase;

class MediaImprovementsTest extends TestCase
{
    private function loginUser(): int
    {
        $id = (int) $this->createUser();
        $this->app->getContainer()->get('session');
        $_SESSION = ['logged' => true, 'user_id' => $id, 'admin' => false, 'csrf' => 'test-csrf'];
        return $id;
    }

    private function media(int $userId, string $filename = 'clip.mp4'): object
    {
        $db = $this->database();
        $db->query('INSERT INTO uploads(user_id,code,filename,storage_path) VALUES(?,?,?,?)', [$userId, bin2hex(random_bytes(5)), $filename, 'test/'.bin2hex(random_bytes(5)).'.mp4']);
        return $db->query('SELECT * FROM uploads WHERE id=?', (int) $db->getPdo()->lastInsertId())->fetch();
    }

    public function testMetadataRenamePreservesStorageAndCodesAndNormalizesTags(): void
    {
        $id = $this->loginUser();
        $media = $this->media($id);
        $response = $this->post(route('upload.metadata', ['id' => $media->id]), [
            'csrf' => 'test-csrf', 'filename' => 'renamed.mp4', 'game' => 'Rocket League',
            'description' => 'Overtime win', 'recorded_at' => '2026-10-22', 'tags' => ' Ranked, ranked, Highlights ',
        ]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $updated = $this->database()->query('SELECT * FROM uploads WHERE id=?', $media->id)->fetch();
        $this->assertSame('renamed.mp4', $updated->filename);
        $this->assertSame($media->code, $updated->code);
        $this->assertSame($media->storage_path, $updated->storage_path);
        $this->assertSame(2, (int) $this->database()->query('SELECT COUNT(*) AS count FROM uploads_tags')->fetch()->count);
    }

    public function testMetadataRejectsOtherOwnersAndExtensionChanges(): void
    {
        $id = $this->loginUser();
        $media = $this->media($id);
        $this->assertSame(422, $this->post(route('upload.metadata', ['id' => $media->id]), ['csrf' => 'test-csrf', 'filename' => 'unsafe.php'])->getStatusCode());
        $_SESSION['user_id'] = (int) $this->createUser(['username' => 'other', 'email' => 'other@example.com']);
        $this->assertSame(404, $this->post(route('upload.metadata', ['id' => $media->id]), ['csrf' => 'test-csrf', 'filename' => 'other.mp4'])->getStatusCode());
    }

    public function testSearchCombinesGameTagsDatesAndOwnership(): void
    {
        $id = $this->loginUser();
        $media = $this->media($id);
        $this->database()->query('UPDATE uploads SET game=?,description=?,timestamp=? WHERE id=?', ['Rocket League', 'Overtime win', '2026-10-22 12:00:00', $media->id]);
        (new TagRepository($this->database()))->addTag('Ranked', $media->id);
        $repo = new MediaRepository($this->database(), $this->app->getContainer()->get('storage'), false);
        $this->assertCount(1, $repo->withUserId($id)->search('rocket ranked overtime')->filters('video', '2026-10-22', '2026-10-22')->run(0)->getMedia());
        $this->assertCount(0, $repo->withUserId($id + 1)->run(0)->getMedia());
        $this->assertCount(0, $repo->withUserId($id)->search('%')->run(0)->getMedia());
    }

    public function testChunkUploadsAcceptOneByteTailAndCompletedRetries(): void
    {
        $id = $this->loginUser();
        $uuid = bin2hex(random_bytes(16));
        $total = 8 * 1024 ** 2 + 1;
        $calls = 0;
        $complete = function ($file) use (&$calls, $total) {
            $calls++;
            $this->assertSame($total, $file->getSize());
            $this->assertSame($total, $file->getStream()->getSize());
            return json(new Response(), ['url' => 'http://localhost/existing/link'], 201);
        };
        $send = function ($offset, $length) use ($id, $uuid, $total, $complete) {
            $stream = fopen('php://temp', 'w+b');
            fwrite($stream, str_repeat('x', $length));
            rewind($stream);
            $request = (new ServerRequest('POST', '/upload/web'))->withParsedBody([
                'dzuuid' => $uuid, 'dztotalfilesize' => (string) $total, 'dzchunkbyteoffset' => (string) $offset,
            ])->withUploadedFiles(['upload' => new UploadedFile(new \Slim\Psr7\Stream($stream), 'clip.mp4', null, $length)]);
            return (new ChunkUploads($this->database()))->receive($request, new Response(), $id, $complete);
        };
        try {
            $this->assertSame(200, $send(0, $total - 1)->getStatusCode());
            $this->assertSame(201, $send($total - 1, 1)->getStatusCode());
            // Simulate process death after the DB committed but before metadata was acknowledged.
            $metaPath = BASE_DIR.'resources/chunks/'.$id.'/'.$uuid.'.json';
            $meta = json_decode(file_get_contents($metaPath), true);
            unset($meta['response']);
            file_put_contents($metaPath, json_encode($meta));
            $this->assertSame(201, $send($total - 1, 1)->getStatusCode());
            $this->assertSame(1, $calls);
        } finally {
            foreach (glob(BASE_DIR.'resources/chunks/'.$id.'/'.$uuid.'.*') as $file) {
                unlink($file);
            }
        }
    }

    public function testSsoDoesNotLinkExistingAccountsByEmail(): void
    {
        $id = $this->loginUser();
        $accounts = new SsoAccounts($this->database());
        try {
            $accounts->resolve('https://auth.example/application/o/xbb/', ['sub' => 'subject', 'email' => 'user@example.com', 'email_verified' => true], null, true);
            $this->fail('Email-only linking must fail.');
        } catch (\RuntimeException) {
            $this->assertSame(0, (int) $this->database()->query('SELECT COUNT(*) AS count FROM sso_identities')->fetch()->count);
        }
        $user = $accounts->resolve('https://auth.example/application/o/xbb/', ['sub' => 'subject'], $id);
        $this->assertSame($id, $user->id);
        $this->assertSame($id, $accounts->resolve('https://auth.example/application/o/xbb/', ['sub' => 'subject'])->id);
    }

    public function testSsoRejectsUnsolicitedCallbackAndDisabledAccounts(): void
    {
        $id = $this->loginUser();
        $this->database()->query('UPDATE users SET active=0 WHERE id=?', $id);
        $this->expectException(\RuntimeException::class);
        (new SsoAccounts($this->database()))->resolve('https://auth.example/', ['sub' => 'subject'], $id);
    }

    public function testByteRangesBeyondTwoGiBAreBoundedAndSupportSuffixes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'xbb-range-');
        $handle = fopen($path, 'w+b');
        $offset = 2 ** 31 + 7;
        fseek($handle, $offset);
        fwrite($handle, 'ABCDEFGH');
        fflush($handle);
        $controller = new class ($this->app->getContainer()) extends \App\Controllers\MediaController {
            public function range($stream, string $range)
            {
                return $this->handlePartialRequest(new Response(), $stream, $range, 'inline', (object) ['filename' => 'video.mp4'], 'video/mp4');
            }
        };
        try {
            $response = $controller->range(new \Slim\Psr7\Stream($handle), "bytes=$offset-".($offset + 2));
            $this->assertSame(206, $response->getStatusCode());
            $this->assertSame('3', $response->getHeaderLine('Content-Length'));
            $this->assertSame('ABC', (string) $response->getBody());
            $response = $controller->range(new \Slim\Psr7\Stream(fopen($path, 'rb')), 'bytes=-2');
            $this->assertSame('GH', (string) $response->getBody());
            $response = $controller->range(new \Slim\Psr7\Stream(fopen($path, 'rb')), 'bytes=99999999999-');
            $this->assertSame(416, $response->getStatusCode());
        } finally {
            @unlink($path);
        }
    }

    public function testAdditiveMigrationKeepsExistingUsersTokensAndUploads(): void
    {
        $id = $this->loginUser();
        $media = $this->media($id);
        $before = $this->database()->query('SELECT * FROM users WHERE id=?', $id)->fetch();
        (new \App\Database\Migrator($this->database(), BASE_DIR.'resources/schemas'))->migrate();
        $after = $this->database()->query('SELECT * FROM users WHERE id=?', $id)->fetch();
        $this->assertSame($before->token, $after->token);
        $this->assertSame($media->storage_path, $this->database()->query('SELECT * FROM uploads WHERE id=?', $media->id)->fetch()->storage_path);
    }
    public function testBrandingSavesAndEscapesTheSiteName(): void
    {
        $this->loginUser();
        $_SESSION['admin'] = true;
        $response = $this->post(route('settings.save'), [
            'csrf' => 'test-csrf', 'default_user_quota' => '1G', 'lang' => 'auto',
            'brand_name' => '<My Clips>', 'brand_logo' => 'https://example.com/logo.png',
            'brand_footer' => 'Our game clips',
        ]);
        $this->assertSame(302, $response->getStatusCode());
        $response = $this->get(route('home'));
        $this->assertStringContainsString('&lt;My Clips&gt;', (string) $response->getBody());
        $this->assertStringContainsString('https://example.com/logo.png', (string) $response->getBody());
    }

    public function testVideoPreviewGeneratesJpegWithoutChangingOriginal(): void
    {
        $root = sys_get_temp_dir().'/xbb-preview-'.bin2hex(random_bytes(5));
        mkdir($root);
        $input = $root.'/sample.mp4';
        $process = proc_open(
            ['ffmpeg', '-v', 'error', '-f', 'lavfi', '-i', 'color=blue:s=320x240:d=1', '-pix_fmt', 'yuv420p', $input],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        $this->assertSame(0, proc_close($process));
        $before = hash_file('sha256', $input);
        $media = (object) ['id' => random_int(100000, 999999), 'storage_path' => 'sample.mp4'];
        $preview = \App\Services\VideoPreviews::path($media);
        try {
            $storage = new \App\Storage\Filesystem(new \League\Flysystem\Local\LocalFilesystemAdapter($root));
            (new \App\Services\VideoPreviews())->generate($media, $storage, ['storage' => ['path' => $root]]);
            $this->assertSame('image/jpeg', getimagesize($preview)['mime']);
            $this->assertSame($before, hash_file('sha256', $input));
        } finally {
            @unlink($input);
            @unlink($preview);
            @rmdir($root);
        }
    }

    public function testSsoCallbackRequiresMatchingStateBeforeContactingProvider(): void
    {
        $this->app->getContainer()->get('session');
        $config = $this->app->getContainer()->get('config');
        $config['sso'] = ['enabled' => true, 'issuer' => 'https://unreachable.invalid/application/o/xbb/', 'client_id' => 'test', 'client_secret' => 'test', 'auto_register' => false];
        $this->app->getContainer()->set('config', $config);
        $this->assertSame(400, $this->get(route('sso.callback'), ['code' => 'unsolicited', 'state' => 'invalid'])->getStatusCode());
    }

    public function testNewSsoUsersRespectTheConfiguredDefaultQuota(): void
    {
        $this->updateSetting('default_user_quota', 123456);
        $user = (new SsoAccounts($this->database()))->resolve('https://auth.example/', [
            'sub' => 'new-user', 'email' => 'new@example.com', 'email_verified' => true,
        ], null, true);
        $this->assertSame(123456, $user->max_disk_quota);
        $this->assertSame(0, $user->is_admin);
    }

}
