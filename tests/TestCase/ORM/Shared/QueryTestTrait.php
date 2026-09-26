<?php
declare(strict_types=1);

namespace Tests\TestCase\ORM\Shared;

use Fyre\Core\Traits\MacroTrait;
use Fyre\ORM\Entity;
use Fyre\ORM\Exceptions\RecordNotFoundException;
use Fyre\ORM\Queries\DeleteQuery;
use Fyre\ORM\Queries\InsertQuery;
use Fyre\ORM\Queries\SelectQuery;
use Fyre\ORM\Queries\UpdateBatchQuery;
use Fyre\ORM\Queries\UpdateQuery;
use Fyre\ORM\Queries\UpsertQuery;
use Tests\Mock\Entities\Item;

use function class_uses;

trait QueryTestTrait
{
    public function testBuffering(): void
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
            ->disableAutoFields()
            ->all();

        $items->toArray();

        $this->assertArraysAreIdentical(
            [
                [
                    'id' => 1,
                ],
                [
                    'id' => 2,
                ],
            ],
            $items->map(static fn(Entity $item): array => $item->toArray())->toArray()
        );
    }

    public function testBufferingDisabled(): void
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
            ->disableAutoFields()
            ->disableBuffering()
            ->all();

        $items->toArray();

        $this->assertArraysAreIdentical(
            [],
            $items->toArray()
        );
    }

    public function testClearResult(): void
    {
        $Items = $this->modelRegistry->use('Items');

        $item1 = $Items->newEntity([
            'name' => 'Test 1',
        ]);

        $this->assertTrue(
            $Items->save($item1)
        );

        $query = $Items->find();

        $this->assertCount(
            1,
            $query->all()->toArray()
        );

        $item2 = $Items->newEntity([
            'name' => 'Test 2',
        ]);

        $this->assertTrue(
            $Items->save($item2)
        );

        $this->assertCount(
            1,
            $query->all()->toArray()
        );

        $this->assertSame(
            $query,
            $query->clearResult()
        );

        $this->assertCount(
            2,
            $query->all()->toArray()
        );
    }

    public function testCount(): void
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

        $this->assertSame(
            2,
            $Items->find()
                ->count()
        );
    }

    public function testCountWithLimit(): void
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

        $this->assertSame(
            1,
            $Items->find()
                ->limit(1)
                ->count()
        );
    }

    public function testDirty(): void
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

        $query = $Items->find();

        $result1 = $query->first();

        $this->assertInstanceOf(
            Item::class,
            $result1
        );

        $this->assertSame(
            'Items',
            $result1->getModelAlias()
        );

        $this->assertSame(
            'Test 1',
            $result1->name
        );

        $query->where([
            'name' => 'Test 2',
        ]);

        $result2 = $query->first();

        $this->assertInstanceOf(
            Item::class,
            $result2
        );

        $this->assertSame(
            'Items',
            $result2->getModelAlias()
        );

        $this->assertSame(
            'Test 2',
            $result2->name
        );
    }

    public function testEnableAutoFields(): void
    {
        $Items = $this->modelRegistry->use('Items');

        $item = $Items->newEntity([
            'name' => 'Test 1',
        ]);

        $this->assertTrue(
            $Items->save($item)
        );

        $query = $Items->find()
            ->disableAutoFields();

        $result = $query->first();

        $this->assertInstanceOf(
            Item::class,
            $result
        );

        $this->assertArraysAreIdentical(
            [
                'id' => 1,
            ],
            $result->toArray()
        );

        $this->assertSame(
            $query,
            $query->enableAutoFields()
        );

        $result = $query->first();

        $this->assertInstanceOf(
            Item::class,
            $result
        );

        $this->assertArraysAreIdentical(
            [
                'id' => 1,
                'name' => 'Test 1',
            ],
            $result->toArray()
        );
    }

    public function testEnableBuffering(): void
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

        $query = $Items->find()
            ->disableAutoFields()
            ->disableBuffering();

        $this->assertSame(
            $query,
            $query->enableBuffering()
        );

        $items = $query->all();

        $this->assertArraysAreIdentical(
            [
                [
                    'id' => 1,
                ],
                [
                    'id' => 2,
                ],
            ],
            $items->map(static fn(Entity $item): array => $item->toArray())->toArray()
        );
    }

    public function testFirstOrFail(): void
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
            ->where(['Items.name' => 'Test 2'])
            ->firstOrFail();

        $this->assertInstanceOf(
            Item::class,
            $item
        );

        $this->assertSame(
            $items[1]->id,
            $item->id
        );
    }

    public function testFirstOrFailMissing(): void
    {
        $this->expectException(RecordNotFoundException::class);
        $this->expectExceptionMessageIs('No matching entity found for model `Items`.');

        $Items = $this->modelRegistry->use('Items');

        $item = $Items->newEntity([
            'name' => 'Test',
        ]);

        $this->assertTrue(
            $Items->save($item)
        );

        $Items->find()
            ->where(['Items.name' => 'Missing'])
            ->firstOrFail();
    }

    public function testMacro(): void
    {
        $this->assertContains(
            MacroTrait::class,
            class_uses(DeleteQuery::class)
        );

        $this->assertContains(
            MacroTrait::class,
            class_uses(InsertQuery::class)
        );

        $this->assertContains(
            MacroTrait::class,
            class_uses(SelectQuery::class)
        );

        $this->assertContains(
            MacroTrait::class,
            class_uses(UpdateQuery::class)
        );

        $this->assertContains(
            MacroTrait::class,
            class_uses(UpsertQuery::class)
        );
    }

    public function testQuery(): void
    {
        $this->assertInstanceOf(
            SelectQuery::class,
            $this->modelRegistry->use('Items')->find()
        );
    }

    public function testUpdateBatchQuery(): void
    {
        $Items = $this->modelRegistry->use('Items');
        $query = $Items->updateBatchQuery();

        $this->assertInstanceOf(
            UpdateBatchQuery::class,
            $query
        );

        $this->assertSame(
            $Items,
            $query->getModel()
        );
    }

    public function testUpsertQuery(): void
    {
        $Items = $this->modelRegistry->use('Items');
        $query = $Items->upsertQuery(['name']);

        $this->assertInstanceOf(
            UpsertQuery::class,
            $query
        );

        $this->assertSame(
            $Items,
            $query->getModel()
        );

        $this->assertArraysAreIdentical(
            ['name'],
            $query->getConflictKeys()
        );
    }
}
