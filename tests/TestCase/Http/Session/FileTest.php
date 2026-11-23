<?php
declare(strict_types=1);

namespace Tests\TestCase\Http\Session;

use Fyre\Core\Config;
use Fyre\Core\Container;
use Fyre\Http\Session\Handlers\FileSessionHandler;
use Fyre\Http\Session\Session;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function clearstatcache;
use function fclose;
use function file_put_contents;
use function filemtime;
use function fileperms;
use function flock;
use function fopen;
use function glob;
use function is_dir;
use function mkdir;
use function random_bytes;
use function rmdir;
use function session_id;
use function session_start;
use function session_write_close;
use function sys_get_temp_dir;
use function time;
use function touch;
use function umask;
use function unlink;

use const LOCK_EX;
use const LOCK_NB;
use const LOCK_UN;
use const PHP_OS_FAMILY;

final class FileTest extends TestCase
{
    protected FileSessionHandler $handler;

    protected Session $session;

    /**
     * @return array<string, array{string}>
     */
    public static function invalidSessionFileProvider(): array
    {
        return [
            'invalid session id' => ['first-notes.txt'],
            'empty session id' => ['first-'],
        ];
    }

    public function testGc(): void
    {
        $id = $this->session->id();

        $this->assertSame(
            '',
            $this->handler->read($id)
        );

        $this->assertTrue(
            $this->handler->write($id, 'data1')
        );

        $this->assertSame(
            1,
            $this->handler->gc(-10)
        );

        $this->assertSame(
            [],
            glob('sessions/*')
        );
    }

    public function testGcDeletesExpiredPrefixedSession(): void
    {
        file_put_contents('sessions/first-old-session,1', 'expired');
        touch('sessions/first-old-session,1', time() - 120);

        $this->assertSame(
            1,
            $this->handler->gc(60)
        );

        $this->assertFileDoesNotExist('sessions/first-old-session,1');
    }

    public function testGcPreservesDirectories(): void
    {
        mkdir('sessions/first-directory');
        touch('sessions/first-directory', time() - 120);

        $this->assertSame(
            0,
            $this->handler->gc(60)
        );

        $this->assertDirectoryExists('sessions/first-directory');
    }

    #[DataProvider('invalidSessionFileProvider')]
    public function testGcPreservesInvalidSessionFiles(string $file): void
    {
        file_put_contents('sessions/'.$file, 'unrelated');
        touch('sessions/'.$file, time() - 120);

        $this->assertSame(
            0,
            $this->handler->gc(60)
        );

        $this->assertFileExists('sessions/'.$file);
    }

    public function testGcPreservesOtherPrefixes(): void
    {
        file_put_contents('sessions/second-old', 'other session');
        touch('sessions/second-old', time() - 120);

        $this->assertSame(
            0,
            $this->handler->gc(60)
        );

        $this->assertFileExists('sessions/second-old');
    }

    public function testGcPreservesUnexpiredSession(): void
    {
        file_put_contents('sessions/first-fresh', 'current');

        $this->assertSame(
            0,
            $this->handler->gc(60)
        );

        $this->assertFileExists('sessions/first-fresh');
    }

    public function testGcPreservesUnrelatedFilesWithoutPrefix(): void
    {
        $handler = new FileSessionHandler($this->session);
        $handler->open('sessions', '');

        file_put_contents('sessions/notes.txt', 'unrelated');
        touch('sessions/notes.txt', time() - 120);

        $this->assertSame(
            0,
            $handler->gc(60)
        );

        $this->assertFileExists('sessions/notes.txt');
    }

    public function testOpenCreatesPrivateDirectory(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('This test requires Unix directory permissions.');
        }

        $suffix = random_bytes(8) |> bin2hex(...);
        $path = sys_get_temp_dir().'/fyre-session-'.$suffix;
        $previousMask = umask(0022);

        try {
            $this->assertTrue($this->handler->open($path, ''));
            $this->assertSame(0700, fileperms($path) & 0777);
        } finally {
            umask($previousMask);

            if (is_dir($path)) {
                rmdir($path);
            }
        }
    }

    public function testOperationLocksAreReleased(): void
    {
        $id = $this->session->id();

        $this->assertTrue(
            $this->handler->write($id, 'data1')
        );

        $this->assertSame(
            'data1',
            $this->handler->read($id)
        );

        $handle = fopen('sessions/first-'.$id, 'c+b');

        $this->assertIsResource($handle);

        $this->assertTrue(
            flock($handle, LOCK_EX | LOCK_NB)
        );

        $this->assertTrue(
            flock($handle, LOCK_UN)
        );

        $this->assertTrue(
            fclose($handle)
        );
    }

    public function testRead(): void
    {
        $id = $this->session->id();

        $this->assertSame(
            '',
            $this->handler->read($id)
        );

        $this->assertTrue(
            $this->handler->write($id, 'data')
        );

        $this->assertSame(
            'data',
            $this->handler->read($id)
        );
    }

    public function testStrictModeRejectsUnknownSessionId(): void
    {
        $suppliedId = 'attacker-selected';
        session_id($suppliedId);

        $this->assertTrue(
            session_start(['use_cookies' => false])
        );

        $generatedId = (string) session_id();

        $this->assertNotSame($suppliedId, $generatedId);

        $this->assertTrue(
            session_write_close()
        );

        $this->assertTrue(
            $this->handler->destroy($generatedId)
        );

        session_id('cli');
    }

    public function testUpdate(): void
    {
        $id = $this->session->id();

        $this->assertSame(
            '',
            $this->handler->read($id)
        );

        $this->assertTrue(
            $this->handler->write($id, 'data1')
        );

        $this->assertSame(
            'data1',
            $this->handler->read($id)
        );

        $this->assertTrue(
            $this->handler->write($id, 'data2')
        );

        $this->assertSame(
            'data2',
            $this->handler->read($id)
        );
    }

    public function testUpdateTimestamp(): void
    {
        $id = $this->session->id();

        $this->assertTrue(
            $this->handler->write($id, 'data')
        );

        $oldTime = time() - 10;

        $this->assertTrue(
            touch('sessions/first-'.$id, $oldTime)
        );

        $this->assertTrue(
            $this->handler->updateTimestamp($id, 'ignored')
        );

        clearstatcache(true, 'sessions/first-'.$id);

        $this->assertGreaterThan(
            $oldTime,
            filemtime('sessions/first-'.$id)
        );

        $this->assertSame(
            'data',
            $this->handler->read($id)
        );

        $this->assertFalse(
            $this->handler->updateTimestamp('missing', 'ignored')
        );
    }

    public function testValidateId(): void
    {
        $id = $this->session->id();

        $this->assertFalse(
            $this->handler->validateId($id)
        );

        $this->assertTrue(
            $this->handler->write($id, 'data')
        );

        $this->assertTrue(
            $this->handler->validateId($id)
        );

        $this->assertTrue(
            touch('sessions/first-'.$id, time() - 86400)
        );

        clearstatcache(true, 'sessions/first-'.$id);

        $this->assertFalse(
            $this->handler->validateId($id)
        );

        $this->assertFalse(
            $this->handler->validateId('../outside')
        );

        $this->assertFalse(
            $this->handler->write("valid\n", 'data')
        );
    }

    #[Override]
    protected function setUp(): void
    {
        $container = new Container();
        $container->singleton(Config::class);
        $container->singleton(Session::class);
        $container->use(Config::class)->set('Session', [
            'handler' => [
                'className' => FileSessionHandler::class,
                'prefix' => 'first-',
            ],
        ]);

        $this->session = $container->use(Session::class);
        $handler = $this->session->getHandler();

        $this->assertInstanceOf(FileSessionHandler::class, $handler);

        $this->handler = $handler;

        $this->session->start();

        @mkdir('sessions');

        $this->assertTrue(
            $this->handler->open('sessions', '')
        );
    }

    #[Override]
    protected function tearDown(): void
    {
        $id = $this->session->id();

        $this->assertTrue(
            $this->handler->destroy($id)
        );

        $this->assertTrue(
            $this->handler->close()
        );

        @unlink('sessions/first-old-session,1');
        @unlink('sessions/first-fresh');
        @unlink('sessions/second-old');
        @unlink('sessions/first-notes.txt');
        @unlink('sessions/first-');
        @unlink('sessions/notes.txt');
        @rmdir('sessions/first-directory');
        @rmdir('sessions');
    }
}
