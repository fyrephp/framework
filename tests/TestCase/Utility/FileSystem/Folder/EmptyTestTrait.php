<?php
declare(strict_types=1);

namespace Tests\TestCase\Utility\FileSystem\Folder;

use Fyre\Utility\FileSystem\File;
use Fyre\Utility\FileSystem\Folder;
use RuntimeException;

trait EmptyTestTrait
{
    public function testEmpty(): void
    {
        $folder = new Folder('tmp/test', true);

        $this->assertSame(
            $folder,
            $folder->empty()
        );

        $this->assertTrue(
            $folder->exists()
        );
    }

    public function testEmptyDeep(): void
    {
        $folder = new Folder('tmp/test', true);
        $file = new File('tmp/test/deep/test.txt', true);

        $this->assertSame(
            $folder,
            $folder->empty()
        );

        $this->assertTrue(
            $folder->exists()
        );

        $this->assertTrue(
            $folder->isEmpty()
        );
    }

    public function testEmptyNotExists(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Failed to open directory');

        $folder = new Folder('tmp/test2');
        $folder->empty();
    }
}
