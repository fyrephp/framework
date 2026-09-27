<?php
declare(strict_types=1);

namespace Tests\TestCase\DB\TypeParser;

use stdClass;

trait SetTestTrait
{
    public function testSetFromDatabase(): void
    {
        $this->assertArraysAreIdentical(
            ['a', 'b', 'c'],
            $this->type->use('set')->fromDatabase('a,b,c')
        );
    }

    public function testSetFromDatabaseNull(): void
    {
        $this->assertNull(
            $this->type->use('set')->fromDatabase(null)
        );
    }

    public function testSetParse(): void
    {
        $result = $this->type->use('set')->parse('a,b,c');

        $this->assertIsArray($result);

        $this->assertArraysAreIdentical(
            ['a', 'b', 'c'],
            $result
        );
    }

    public function testSetParseArray(): void
    {
        $result = $this->type->use('set')->parse(['a', 'b', 'c']);

        $this->assertIsArray($result);

        $this->assertArraysAreIdentical(
            ['a', 'b', 'c'],
            $result
        );
    }

    public function testSetParseInvalid(): void
    {
        $this->assertNull(
            $this->type->use('set')->parse(new stdClass())
        );
    }

    public function testSetParseNull(): void
    {
        $this->assertNull(
            $this->type->use('set')->parse(null)
        );
    }

    public function testSetToDatabase(): void
    {
        $this->assertSame(
            'a,b,c',
            $this->type->use('set')->toDatabase(['a', 'b', 'c'])
        );
    }

    public function testSetToDatabaseInvalid(): void
    {
        $this->assertNull(
            $this->type->use('set')->toDatabase(new stdClass())
        );
    }

    public function testSetToDatabaseNull(): void
    {
        $this->assertNull(
            $this->type->use('set')->toDatabase(null)
        );
    }

    public function testSetToDatabaseString(): void
    {
        $this->assertSame(
            'a,b,c',
            $this->type->use('set')->toDatabase('a,b,c')
        );
    }
}
