<?php
declare(strict_types=1);

namespace Tests\TestCase\Utility\FileSystem\Folder;

use Fyre\Utility\FileSystem\File;
use Fyre\Utility\FileSystem\Folder;
use RuntimeException;

trait DeleteTestTrait
{
    public function testDelete(): void
    {
        $folder = new Folder('tmp/test2', true);

        $this->assertSame(
            $folder,
            $folder->delete()
        );

        $this->assertFalse(
            $folder->exists()
        );
    }

    public function testDeleteDeep(): void
    {
        $folder = new Folder('tmp/test', true);
        $file = new File('tmp/test/file/test.txt', true);

        $folder->delete();

        $this->assertFalse(
            $file->exists()
        );

        $this->assertFalse(
            $folder->exists()
        );
    }

    public function testDeleteNotExists(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Failed to open directory: No such file or directory');

        $folder = new Folder('tmp/test2');
        $folder->delete();
    }
}
