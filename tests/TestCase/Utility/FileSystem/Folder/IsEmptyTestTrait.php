<?php
declare(strict_types=1);

namespace Tests\TestCase\Utility\FileSystem\Folder;

use Fyre\Utility\FileSystem\File;
use Fyre\Utility\FileSystem\Folder;
use RuntimeException;

trait IsEmptyTestTrait
{
    public function testIsEmpty(): void
    {
        $folder = new Folder('tmp/test', true);

        $this->assertTrue(
            $folder->isEmpty()
        );
    }

    public function testIsEmptyNotEmpty(): void
    {
        $folder = new Folder('tmp/test', true);
        $folder2 = new Folder('tmp/test/deep', true);

        $this->assertFalse(
            $folder->isEmpty()
        );
    }

    public function testIsEmptyNotExists(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Failed to open directory: No such file or directory');

        $folder = new Folder('tmp/test');
        $folder->isEmpty();
    }

    public function testIsEmptyWithFile(): void
    {
        $folder = new Folder('tmp/test', true);
        $file = new File('tmp/test/test.txt', true);

        $this->assertFalse(
            $folder->isEmpty()
        );
    }
}
