<?php
declare(strict_types=1);

namespace Tests\TestCase\Utility\FileSystem\Folder;

use Fyre\Utility\FileSystem\File;
use Fyre\Utility\FileSystem\Folder;
use RuntimeException;

trait SizeTestTrait
{
    public function testSize(): void
    {
        $folder = new Folder('tmp/test', true);

        $this->assertSame(
            0,
            $folder->size()
        );
    }

    public function testSizeDeep(): void
    {
        $folder = new Folder('tmp/test', true);

        $file = new File('tmp/test/deep/test.txt', true);
        $file->open('wssss');
        $file->write('test');
        $file->close();

        $this->assertSame(
            4100,
            $folder->size()
        );
    }

    public function testSizeNotExists(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Failed to open directory: No such file or directory');

        $folder = new Folder('tmp/test');
        $folder->size();
    }
}
