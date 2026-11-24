<?php
declare(strict_types=1);

namespace Tests\TestCase\Utility\FileSystem\Folder;

use Fyre\Utility\FileSystem\File;
use Fyre\Utility\FileSystem\Folder;
use Fyre\Utility\Path;
use RuntimeException;

trait CopyTestTrait
{
    public function testCopy(): void
    {
        $folder = new Folder('tmp/test', true);

        $this->assertSame(
            $folder,
            $folder->copy('tmp/test2')
        );

        $folder2 = new Folder('tmp/test2');

        $this->assertSame(
            Path::resolve('tmp/test'),
            $folder->path()
        );

        $this->assertTrue(
            $folder->exists()
        );

        $this->assertTrue(
            $folder2->exists()
        );
    }

    public function testCopyDeep(): void
    {
        $folder = new Folder('tmp/test', true);
        $file = new File('tmp/test/deep/test.txt', true);

        $folder->copy('tmp/test2');

        $file2 = new File('tmp/test2/deep/test.txt');

        $this->assertTrue(
            $file->exists()
        );

        $this->assertTrue(
            $file2->exists()
        );
    }

    public function testCopyNotExists(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Failed to open directory: No such file or directory');

        $folder = new Folder('tmp/test');
        $folder->copy('tmp/test2');
    }

    public function testCopyNotOverwrite(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('File `tmp/test2/deep/test.txt` already exists.');

        $folder = new Folder('tmp/test', true);
        new File('tmp/test/deep/test.txt', true);
        new File('tmp/test2/deep/test.txt', true);
        $folder->copy('tmp/test2', false);
    }
}
