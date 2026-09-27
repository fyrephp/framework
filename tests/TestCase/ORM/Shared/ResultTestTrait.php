<?php
declare(strict_types=1);

namespace Tests\TestCase\ORM\Shared;

use Fyre\Core\Traits\MacroTrait;
use Fyre\ORM\Result;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Mock\Entities\Item;
use Tests\Mock\Enums\State;
use Tests\Mock\Enums\Status;

use function class_uses;
use function json_encode;

trait ResultTestTrait
{
    /**
     * @return array<string, array{class-string<State>|class-string<Status>, string}>
     */
    public static function resultHydratesInvalidEnumAsNullProvider(): array
    {
        return [
            'enum null' => [Status::class, 'INSERT INTO items (name) VALUES (NULL)'],
            'invalid enum as null' => [Status::class, "INSERT INTO items (name) VALUES ('invalid')"],
            'invalid unit enum as null' => [State::class, "INSERT INTO items (name) VALUES ('Invalid')"],
        ];
    }

    public function testCollection(): void
    {
        $Items = $this->modelRegistry->use('Items');

        $items = $Items->newEntities([
            [
                'name' => 'Test 1',
            ],
            [
                'name' => 'Test 2',
            ],
        ]);

        $this->assertTrue(
            $Items->saveMany($items)
        );

        $items = $Items->find()
            ->getResult();

        $this->assertArraysAreIdentical(
            [
                1 => 'Test 1',
                2 => 'Test 2',
            ],
            $items->combine('id', 'name')->toArray()
        );
    }

    public function testColumnCount(): void
    {
        $this->assertSame(
            2,
            $this->modelRegistry->use('Items')
                ->find()
                ->getResult()
                ->columnCount()
        );
    }

    public function testColumns(): void
    {
        $this->assertArraysAreIdentical(
            [
                'Items__id',
                'Items__name',
            ],
            $this->modelRegistry->use('Items')
                ->find()
                ->getResult()
                ->columns()
        );
    }

    public function testFetch(): void
    {
        $Items = $this->modelRegistry->use('Items');

        $items = $Items->newEntities([
            [
                'name' => 'Test 1',
            ],
            [
                'name' => 'Test 2',
            ],
        ]);

        $this->assertTrue(
            $Items->saveMany($items)
        );

        $item = $Items->find()
            ->getResult()
            ->fetch(1);

        $this->assertInstanceOf(
            Item::class,
            $item
        );

        $this->assertSame(
            'Items',
            $item->getModelAlias()
        );

        $this->assertSame(
            2,
            $item->id
        );
    }

    public function testFirst(): void
    {
        $Items = $this->modelRegistry->use('Items');

        $items = $Items->newEntities([
            [
                'name' => 'Test 1',
            ],
            [
                'name' => 'Test 2',
            ],
        ]);

        $this->assertTrue(
            $Items->saveMany($items)
        );

        $item = $Items->find()
            ->getResult()
            ->first();

        $this->assertInstanceOf(
            Item::class,
            $item
        );

        $this->assertSame(
            'Items',
            $item->getModelAlias()
        );

        $this->assertSame(
            1,
            $item->id
        );
    }

    public function testFree(): void
    {
        $Items = $this->modelRegistry->use('Items');

        $items = $Items->newEntities([
            [
                'name' => 'Test 1',
            ],
            [
                'name' => 'Test 2',
            ],
        ]);

        $this->assertTrue(
            $Items->saveMany($items)
        );

        $result = $Items->find()->getResult();
        $result->free();

        $this->assertArraysAreIdentical(
            [],
            $result->toArray()
        );
    }

    public function testJson(): void
    {
        $Items = $this->modelRegistry->use('Items');

        $items = $Items->newEntities([
            [
                'name' => 'Test 1',
            ],
            [
                'name' => 'Test 2',
            ],
        ]);

        $this->assertTrue(
            $Items->saveMany($items)
        );

        $items = $Items->find()
            ->getResult();

        $this->assertSame(
            '[{"id":1,"name":"Test 1"},{"id":2,"name":"Test 2"}]',
            json_encode($items)
        );
    }

    public function testLast(): void
    {
        $Items = $this->modelRegistry->use('Items');

        $items = $Items->newEntities([
            [
                'name' => 'Test 1',
            ],
            [
                'name' => 'Test 2',
            ],
        ]);

        $this->assertTrue(
            $Items->saveMany($items)
        );

        $item = $Items->find()
            ->getResult()
            ->last();

        $this->assertInstanceOf(
            Item::class,
            $item
        );

        $this->assertSame(
            'Items',
            $item->getModelAlias()
        );

        $this->assertSame(
            2,
            $item->id
        );
    }

    public function testMacro(): void
    {
        $this->assertContains(
            MacroTrait::class,
            class_uses(Result::class)
        );
    }

    public function testResult(): void
    {
        $this->assertInstanceOf(
            Result::class,
            $this->modelRegistry->use('Items')->find()->getResult()
        );
    }

    public function testResultHydratesEnum(): void
    {
        $Items = $this->modelRegistry->use('Items');
        $Items->getSchema()->setEnumClass('name', Status::class);

        $this->db->query("INSERT INTO items (name) VALUES ('draft')");

        $item = $Items->find()
            ->getResult()
            ->first();

        $this->assertInstanceOf(
            Item::class,
            $item
        );

        $this->assertSame(
            Status::Draft,
            $item->name
        );
    }

    /**
     * @param class-string<State>|class-string<Status> $enumClass
     */
    #[DataProvider('resultHydratesInvalidEnumAsNullProvider')]
    public function testResultHydratesInvalidEnumAsNull(string $enumClass, string $sql): void
    {
        $Items = $this->modelRegistry->use('Items');
        $Items->getSchema()->setEnumClass('name', $enumClass);

        $this->db->query($sql);

        $item = $Items->find()
            ->getResult()
            ->first();

        $this->assertInstanceOf(
            Item::class,
            $item
        );

        $this->assertNull(
            $item->name
        );
    }

    public function testResultHydratesUnitEnum(): void
    {
        $Items = $this->modelRegistry->use('Items');
        $Items->getSchema()->setEnumClass('name', State::class);

        $this->db->query("INSERT INTO items (name) VALUES ('Draft')");

        $item = $Items->find()
            ->getResult()
            ->first();

        $this->assertInstanceOf(
            Item::class,
            $item
        );

        $this->assertSame(
            State::Draft,
            $item->name
        );
    }
}
