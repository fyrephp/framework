<?php
declare(strict_types=1);

namespace Tests\TestCase\Utility\FileSystem\File;

use Fyre\Core\Exceptions\ErrorException;
use Fyre\Utility\FileSystem\File;

trait ContentsTestTrait
{
    public function testContents(): void
    {
        $file = new File('tmp/test.txt', true);
        $file->open('w');
        $file->write('test');
        $file->close();

        $this->assertSame(
            'test',
            $file->contents()
        );
    }

    public function testContentsNotExists(): void
    {
        $this->expectException(ErrorException::class);
        $this->expectExceptionMessageIsOrContains('Failed to open stream: No such file or directory');

        $file = new File('tmp/test.txt');
        $file->contents();
    }
}
