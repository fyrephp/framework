<?php
declare(strict_types=1);

namespace Tests\TestCase\DB\TypeParser;

use function base64_encode;
use function fclose;
use function fopen;
use function fread;

trait BinaryTestTrait
{
    public function testBinaryFromDatabase(): void
    {
        $handle = $this->type->use('binary')->fromDatabase('test');

        $this->assertIsResource($handle);

        $this->assertSame(
            'test',
            fread($handle, 1024)
        );

        fclose($handle);
    }

    public function testBinaryFromDatabaseNull(): void
    {
        $this->assertNull(
            $this->type->use('binary')->fromDatabase(null)
        );
    }

    public function testBinaryFromDatabaseResource(): void
    {
        $handle1 = fopen('data:text/plain;base64,'.base64_encode('test'), 'rb');
        $this->assertIsResource($handle1);

        $handle2 = $this->type->use('binary')->fromDatabase($handle1);

        $this->assertSame(
            $handle1,
            $handle2
        );

        fclose($handle1);
    }

    public function testBinaryParse(): void
    {
        $this->assertSame(
            'test',
            $this->type->use('binary')->parse('test')
        );
    }

    public function testBinaryParseNull(): void
    {
        $this->assertNull(
            $this->type->use('binary')->parse(null)
        );
    }

    public function testBinaryParseResource(): void
    {
        $handle1 = fopen('data:text/plain;base64,'.base64_encode('test'), 'rb');
        $this->assertIsResource($handle1);

        $handle2 = $this->type->use('binary')->parse($handle1);

        $this->assertSame(
            $handle1,
            $handle2
        );

        fclose($handle1);
    }

    public function testBinaryToDatabase(): void
    {
        $this->assertSame(
            'test',
            $this->type->use('binary')->toDatabase('test')
        );
    }

    public function testBinaryToDatabaseNull(): void
    {
        $this->assertNull(
            $this->type->use('binary')->toDatabase(null)
        );
    }

    public function testBinaryToDatabaseResource(): void
    {
        $handle1 = fopen('data:text/plain;base64,'.base64_encode('test'), 'rb');
        $this->assertIsResource($handle1);

        $handle2 = $this->type->use('binary')->parse($handle1);

        $this->assertSame(
            $handle1,
            $handle2
        );

        fclose($handle1);
    }
}
