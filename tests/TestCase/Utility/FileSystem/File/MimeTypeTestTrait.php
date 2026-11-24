<?php
declare(strict_types=1);

namespace Tests\TestCase\Utility\FileSystem\File;

use Fyre\Core\Exceptions\ErrorException;
use Fyre\Utility\FileSystem\File;

trait MimeTypeTestTrait
{
    public function testMimeType(): void
    {
        $file = new File('tmp/test.txt', true);
        $file->open('w');
        $file->write('test');
        $file->close();

        $this->assertSame(
            'text/plain',
            $file->mimeType()
        );
    }

    public function testMimeTypeNotExists(): void
    {
        $this->expectException(ErrorException::class);
        $this->expectExceptionMessageIsOrContains('Failed to open stream: No such file or directory');

        $file = new File('tmp/test.txt');
        $file->mimeType();
    }
}
