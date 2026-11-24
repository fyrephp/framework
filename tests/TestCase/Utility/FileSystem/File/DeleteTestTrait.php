<?php
declare(strict_types=1);

namespace Tests\TestCase\Utility\FileSystem\File;

use Fyre\Core\Exceptions\ErrorException;
use Fyre\Utility\FileSystem\File;

trait DeleteTestTrait
{
    public function testDelete(): void
    {
        $file = new File('tmp/test/test.txt', true);

        $this->assertSame(
            $file,
            $file->delete()
        );

        $this->assertFalse(
            $file->exists()
        );
    }

    public function testDeleteNotExists(): void
    {
        $this->expectException(ErrorException::class);
        $this->expectExceptionMessageIsOrContains('No such file or directory');

        $file = new File('tmp/test.txt');
        $file->delete();
    }
}
