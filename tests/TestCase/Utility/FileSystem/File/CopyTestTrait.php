<?php
declare(strict_types=1);

namespace Tests\TestCase\Utility\FileSystem\File;

use Fyre\Core\Exceptions\ErrorException;
use Fyre\Utility\FileSystem\File;
use RuntimeException;

trait CopyTestTrait
{
    public function testCopy(): void
    {
        $file = new File('tmp/test.txt', true);

        $this->assertSame(
            $file,
            $file->copy('tmp/test2.txt')
        );

        $file2 = new File('tmp/test2.txt');

        $this->assertTrue(
            $file2->exists()
        );
    }

    public function testCopyFolderNotExists(): void
    {
        $this->expectException(ErrorException::class);
        $this->expectExceptionMessageIsOrContains('Failed to open stream: No such file or directory');

        $file = new File('tmp/test.txt', true);
        $file->copy('tmp/test/test2.txt');
    }

    public function testCopyNotExists(): void
    {
        $this->expectException(ErrorException::class);
        $this->expectExceptionMessageIsOrContains('Failed to open stream: No such file or directory');

        $file = new File('tmp/test.txt');
        $file->copy('tmp/test2.txt');
    }

    public function testCopyNotOverwrite(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('File `tmp/test2.txt` already exists.');

        $file = new File('tmp/test.txt', true);
        new File('tmp/test2.txt', true);
        $file->copy('tmp/test2.txt', false);
    }
}
