<?php
declare(strict_types=1);

namespace Tests\TestCase\ORM\Shared\Model;

use Fyre\DB\Expressions\ConditionExpression;
use Fyre\DB\Query;
use Fyre\Event\Event;
use Fyre\ORM\Entity;
use Fyre\ORM\Exceptions\OrmException;
use Generator;
use Tests\Mock\Entities\Item;
use Tests\Mock\Entities\Post;
use Tests\Mock\Entities\User;

use function array_map;
use function range;

trait QueryTestTrait
{
    public function testDelete(): void
    {
        $Items = $this->modelRegistry->use('Items');

        $item = $Items->newEntity([
            'name' => 'Test',
        ]);

        $this->assertTrue(
            $Items->save($item)
        );

        $this->assertTrue(
            $Items->delete($item)
        );

        $this->assertSame(
            0,
            $Items->find()->count()
        );
    }

    public function testDeleteCascadeForeignKeys(): void
    {
        $this->db->enableForeignKeys();

        $Parents = $this->modelRegistry->use('CascadeParents');
        $Children = $this->modelRegistry->use('CascadeChildren');
        $Parents->hasMany('CascadeChildren', [
            'dependent' => true,
        ]);

        $parent = $Parents->newEntity(['id' => 1]);
        $child = $Children->newEntity([
            'id' => 1,
            'cascade_parent_id' => 1,
        ]);

        $this->assertTrue(
            $Parents->save($parent)
        );
        $this->assertTrue(
            $Children->save($child)
        );

        $this->assertTrue(
            $Parents->delete($parent)
        );
        $this->assertSame(
            0,
            $Parents->find()->count()
        );
        $this->assertSame(
            0,
            $Children->find()->count()
        );
    }

    public function testDeleteCascadeForeignKeysCancelled(): void
    {
        $this->db->enableForeignKeys();

        $Parents = $this->modelRegistry->use('CascadeParents');
        $Children = $this->modelRegistry->use('CascadeChildren');
        $Parents->hasMany('CascadeChildren', [
            'dependent' => true,
        ]);

        $parent = $Parents->newEntity(['id' => 1]);
        $child = $Children->newEntity([
            'id' => 1,
            'cascade_parent_id' => 1,
        ]);

        $this->assertTrue(
            $Parents->save($parent)
        );
        $this->assertTrue(
            $Children->save($child)
        );

        $Parents->getEventManager()->on('ORM.afterDelete', static function(Event $event): void {
            $event->setResult(false);
            $event->stopPropagation();
        });

        $this->assertFalse(
            $Parents->delete($parent)
        );
        $this->assertSame(
            1,
            $Parents->find()->count()
        );
        $this->assertSame(
            1,
            $Children->find()->count()
        );
    }

    public function testDeleteChangedPrimaryKey(): void
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

        $items[0]->set('id', $items[1]->id);

        $caught = null;

        try {
            $Items->delete($items[0]);
        } catch (OrmException $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(
            OrmException::class,
            $caught
        );
        $this->assertSame(
            'Primary key values for model `Items` must not be changed.',
            $caught->getMessage()
        );
        $this->assertArraysAreIdentical(
            ['Test 1', 'Test 2'],
            $Items->find(orderBy: ['id' => 'ASC'])
                ->all()
                ->map(static fn(Entity $item): string => $item->name)
                ->toArray()
        );
        $this->assertSame(
            0,
            $this->db->getSavePointLevel()
        );
    }

    public function testDeleteChangedPrimaryKeyInCallback(): void
    {
        $this->expectException(OrmException::class);
        $this->expectExceptionMessageIs('Primary key values for model `Items` must not be changed.');

        $Items = $this->modelRegistry->use('Items');
        $item = $Items->newEntity([
            'name' => 'Test',
        ]);

        $this->assertTrue(
            $Items->save($item)
        );

        $Items->getEventManager()->on('ORM.beforeDelete', static function(Event $event, Entity $entity): void {
            $entity->set('id', 10);
        });

        $Items->delete($item);
    }

    public function testDeleteIncompleteCompositePrimaryKey(): void
    {
        $this->expectException(OrmException::class);
        $this->expectExceptionMessageIs('Primary key values for model `CompositeItems` must not be null or missing.');

        $CompositeItems = $this->modelRegistry->use('CompositeItems');
        $item = $CompositeItems->newEntity([
            'tenant_id' => 1,
        ], validate: false, new: false);

        $CompositeItems->delete($item);
    }

    public function testDeleteMany(): void
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

        $this->assertTrue(
            $Items->deleteMany($items)
        );

        $this->assertSame(
            0,
            $Items->find()->count()
        );
    }

    public function testDeleteManyCascadeForeignKeys(): void
    {
        $this->db->enableForeignKeys();

        $Parents = $this->modelRegistry->use('CascadeParents');
        $Children = $this->modelRegistry->use('CascadeChildren');
        $Parents->hasMany('CascadeChildren', [
            'dependent' => true,
        ]);

        $parents = $Parents->newEntities([
            ['id' => 1],
            ['id' => 2],
            ['id' => 3],
        ]);
        $children = $Children->newEntities([
            ['id' => 1, 'cascade_parent_id' => 1],
            ['id' => 2, 'cascade_parent_id' => 2],
            ['id' => 3, 'cascade_parent_id' => 3],
        ]);

        $this->assertTrue(
            $Parents->saveMany($parents)
        );
        $this->assertTrue(
            $Children->saveMany($children)
        );

        $this->assertTrue(
            $Parents->deleteMany([$parents[0], $parents[1]])
        );
        $this->assertSame(
            1,
            $Parents->find()->count()
        );
        $this->assertSame(
            1,
            $Children->find()->count()
        );
        $this->assertTrue(
            $Children->exists(['id' => 3, 'cascade_parent_id' => 3])
        );
    }

    public function testDeleteManyChangedCompositePrimaryKey(): void
    {
        $CompositeItems = $this->modelRegistry->use('CompositeItems');
        $items = $CompositeItems->newEntities([
            [
                'tenant_id' => 1,
                'id' => 1,
                'name' => 'Test 1',
            ],
            [
                'tenant_id' => 2,
                'id' => 1,
                'name' => 'Test 2',
            ],
            [
                'tenant_id' => 3,
                'id' => 1,
                'name' => 'Test 3',
            ],
        ]);

        $this->assertTrue(
            $CompositeItems->saveMany($items)
        );

        $items[1]->set('tenant_id', 3);

        $caught = null;

        try {
            $CompositeItems->deleteMany([$items[0], $items[1]]);
        } catch (OrmException $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(
            OrmException::class,
            $caught
        );
        $this->assertArraysAreIdentical(
            ['Test 1', 'Test 2', 'Test 3'],
            $CompositeItems->find(orderBy: ['tenant_id' => 'ASC'])
                ->all()
                ->map(static fn(Entity $item): string => $item->get('name'))
                ->toArray()
        );
        $this->assertSame(
            0,
            $this->db->getSavePointLevel()
        );
    }

    public function testDeleteManyEmpty(): void
    {
        $this->assertTrue(
            $this->modelRegistry->use('Items')->deleteMany([])
        );
    }

    public function testDeleteManyGeneratorDuplicateKeys(): void
    {
        $Items = $this->modelRegistry->use('Items');
        $items = $Items->newEntities([
            ['name' => 'First'],
            ['name' => 'Second'],
        ]);

        $this->assertTrue(
            $Items->saveMany($items)
        );

        $generator = static function() use ($items): Generator {
            yield from [$items[0]];
            yield from [$items[1]];
        };

        $this->assertTrue(
            $Items->deleteMany($generator())
        );
        $this->assertSame(
            0,
            $Items->find()->count()
        );
    }

    public function testDeleteManyKeyedSingle(): void
    {
        $Items = $this->modelRegistry->use('Items');

        foreach ([1, 'item'] as $key) {
            $item = $Items->newEntity([
                'name' => 'Test',
            ]);

            $this->assertTrue(
                $Items->save($item)
            );

            $this->assertTrue(
                $Items->deleteMany([$key => $item])
            );

            $this->assertSame(
                0,
                $Items->find()->count()
            );
        }
    }

    public function testDeleteNonDependentForeignKeys(): void
    {
        $this->db->enableForeignKeys();

        $Parents = $this->modelRegistry->use('CascadeParents');
        $Children = $this->modelRegistry->use('CascadeChildren');
        $Parents->hasMany('CascadeChildren', [
            'dependent' => false,
        ]);

        $parent = $Parents->newEntity(['id' => 1]);
        $child = $Children->newEntity([
            'id' => 1,
            'cascade_parent_id' => 1,
        ]);

        $this->assertTrue(
            $Parents->save($parent)
        );
        $this->assertTrue(
            $Children->save($child)
        );

        $this->assertTrue(
            $Parents->delete($parent)
        );
        $this->assertSame(
            0,
            $Parents->find()->count()
        );

        $child = $Children->get(1);

        $this->assertInstanceOf(
            Entity::class,
            $child
        );
        $this->assertNull(
            $child->get('cascade_parent_id')
        );
    }

    public function testDeleteRestoredPrimaryKeyInCallback(): void
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

        $id = $items[0]->id;
        $items[0]->set('id', $items[1]->id);

        $Items->getEventManager()->on('ORM.beforeDelete', static function(Event $event, Entity $entity) use ($id): void {
            $entity->set('id', $id);
        });

        $this->assertTrue(
            $Items->delete($items[0])
        );
        $this->assertArraysAreIdentical(
            ['Test 2'],
            $Items->find()
                ->all()
                ->map(static fn(Entity $item): string => $item->name)
                ->toArray()
        );
    }

    public function testExists(): void
    {
        $Items = $this->modelRegistry->use('Items');

        $item = $Items->newEntity([
            'name' => 'Test',
        ]);

        $this->assertTrue(
            $Items->save($item)
        );

        $this->assertTrue(
            $Items->exists(['name' => 'Test'])
        );
    }

    public function testExistsNotExists(): void
    {
        $this->assertFalse(
            $this->modelRegistry->use('Items')->exists(['name' => 'Test'])
        );
    }

    public function testFindAutoFields(): void
    {
        $Items = $this->modelRegistry->use('Items');

        $item = $Items->newEntity([
            'name' => 'Test',
        ]);

        $this->assertTrue(
            $Items->save($item)
        );

        $item = $Items->get(1, autoFields: false);

        $this->assertInstanceOf(
            Item::class,
            $item
        );

        $this->assertArraysAreIdentical(
            [
                'id' => 1,
            ],
            $item->toArray()
        );
    }

    public function testFindCountTriggersBeforeFindOnce(): void
    {
        $count = 0;
        $Items = $this->modelRegistry->use('Items');

        $Items->getEventManager()->on('ORM.beforeFind', static function() use (&$count): void {
            $count++;
        });

        $Items->find()->count();

        $this->assertSame(
            1,
            $count
        );
    }

    public function testGet(): void
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

        $item = $Items->get(
            2,
            conditions: static fn(Query $query): ConditionExpression => $query->expr()
                ->eq('Items.name', 'Test 2')
        );

        $this->assertInstanceOf(
            Item::class,
            $item
        );

        $this->assertSame(
            2,
            $item->id
        );
    }

    public function testGetIncompleteCompositePrimaryKey(): void
    {
        $this->expectException(OrmException::class);
        $this->expectExceptionMessageIs('Primary key values for model `CompositeItems` must not be null or missing.');

        $this->modelRegistry->use('CompositeItems')->get([1]);
    }

    public function testGetInvalid(): void
    {
        $this->assertNull(
            $this->modelRegistry->use('Items')->get(1)
        );
    }

    public function testInsert(): void
    {
        $Items = $this->modelRegistry->use('Items');

        $item = $Items->newEntity([
            'name' => 'Test',
        ]);

        $this->assertTrue(
            $Items->save($item)
        );

        $this->assertSame(
            1,
            $item->id
        );

        $this->assertFalse(
            $item->isNew()
        );

        $this->assertFalse(
            $item->isDirty()
        );
    }

    public function testInsertMany(): void
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

        $this->assertArraysAreIdentical(
            [1, 2],
            array_map(
                static fn(Item $item): int|null => $item->id,
                $items
            )
        );

        $this->assertFalse(
            $items[0]->isNew()
        );

        $this->assertFalse(
            $items[1]->isNew()
        );

        $this->assertFalse(
            $items[0]->isDirty()
        );

        $this->assertFalse(
            $items[1]->isDirty()
        );
    }

    public function testInsertManyBatch(): void
    {
        $Items = $this->modelRegistry->use('Items');

        $data = [];

        for ($i = 0; $i < 1000; $i++) {
            $data[] = [
                'name' => 'Test '.($i + 1),
            ];
        }

        $items = $Items->newEntities($data);

        $this->assertTrue(
            $Items->saveMany($items)
        );

        $this->assertArraysAreIdentical(
            range(1, 1000),
            array_map(
                static fn(Item $item): int|null => $item->id,
                $items
            )
        );
    }

    public function testInsertManyChecksExactCompositePrimaryKeys(): void
    {
        $CompositeItems = $this->modelRegistry->use('CompositeItems');

        $CompositeItems->insertQuery()
            ->values([[
                'tenant_id' => 1,
                'id' => 1,
                'name' => 'Existing',
            ]])
            ->execute();

        $items = $CompositeItems->newEntities([
            [
                'tenant_id' => 1,
                'id' => 1,
                'name' => 'Updated',
            ],
            [
                'tenant_id' => 1,
                'id' => 2,
                'name' => 'New',
            ],
        ], validate: false);

        $this->assertTrue(
            $CompositeItems->saveMany($items)
        );

        $this->assertArraysAreIdentical(
            [
                [
                    'tenant_id' => 1,
                    'id' => 1,
                    'name' => 'Updated',
                ],
                [
                    'tenant_id' => 1,
                    'id' => 2,
                    'name' => 'New',
                ],
            ],
            array_map(
                static fn(Entity $item): array => $item->toArray(),
                $CompositeItems->find(orderBy: [
                    'tenant_id' => 'ASC',
                    'id' => 'ASC',
                ])->toArray()
            )
        );
    }

    public function testResolveRouteBinding(): void
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

        $item = $Items->resolveRouteBinding(2, 'id');

        $this->assertInstanceOf(
            Item::class,
            $item
        );

        $this->assertSame(
            2,
            $item->id
        );
    }

    public function testResolveRouteBindingInvalid(): void
    {
        $this->assertNull(
            $this->modelRegistry->use('Items')->resolveRouteBinding(1, 'id')
        );
    }

    public function testResolveRouteBindingParent(): void
    {
        $Users = $this->modelRegistry->use('Users');
        $Posts = $this->modelRegistry->use('Posts');

        $user = $Users->newEntity([
            'name' => 'Test',
            'posts' => [
                [
                    'title' => 'Test 1',
                    'content' => 'This is the content.',
                ],
                [
                    'title' => 'Test 2',
                    'content' => 'This is the content.',
                ],
            ],
        ]);

        $this->assertTrue(
            $Users->save($user)
        );

        $user = $Users->resolveRouteBinding(1, 'id');

        $this->assertInstanceOf(
            User::class,
            $user
        );

        $item = $Posts->resolveRouteBinding(2, 'id', $user);

        $this->assertInstanceOf(
            Post::class,
            $item
        );

        $this->assertSame(
            2,
            $item->id
        );
    }

    public function testResolveRouteBindingParentInvalid(): void
    {
        $Users = $this->modelRegistry->use('Users');
        $Posts = $this->modelRegistry->use('Posts');

        $user = $Users->newEntity([
            'name' => 'Test',
            'posts' => [
                [
                    'title' => 'Test 1',
                    'content' => 'This is the content.',
                ],
                [
                    'title' => 'Test 2',
                    'content' => 'This is the content.',
                ],
            ],
        ]);

        $this->assertTrue(
            $Users->save($user)
        );

        $user = $Users->newEntity([
            'name' => 'Test 2',
        ]);

        $this->assertTrue(
            $Users->save($user)
        );

        $user = $Users->resolveRouteBinding(2, 'id');
        $item = $Posts->resolveRouteBinding(2, 'id', $user);

        $this->assertNull($item);
    }

    public function testSaveManyAfterFilteringCleanEntity(): void
    {
        $Items = $this->modelRegistry->use('Items');
        $cleanItem = $Items->newEntity([
            'name' => 'Existing',
        ]);

        $this->assertTrue(
            $Items->save($cleanItem)
        );

        $newItem = $Items->newEntity([
            'name' => 'New',
        ]);

        $this->assertTrue(
            $Items->saveMany([$cleanItem, $newItem])
        );

        $this->assertSame(
            2,
            $Items->find()->count()
        );

        $this->assertTrue(
            $Items->exists(['name' => 'New'])
        );
    }

    public function testSaveManyErrors(): void
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

        $items[1]->setError('name', 'Invalid value.');

        $this->assertFalse(
            $Items->saveMany($items)
        );
    }

    public function testSaveManyGeneratorDuplicateKeys(): void
    {
        $Items = $this->modelRegistry->use('Items');
        $items = $Items->newEntities([
            ['name' => 'First'],
            ['name' => 'Second'],
        ]);

        $generator = static function() use ($items): Generator {
            yield from [$items[0]];
            yield from [$items[1]];
        };

        $this->assertTrue(
            $Items->saveMany($generator())
        );
        $this->assertSame(
            ['First', 'Second'],
            $Items->find(orderBy: ['id' => 'ASC'])
                ->all()
                ->map(static fn(Entity $item): string => $item->name)
                ->toArray()
        );
    }

    public function testSaveManyKeyedSingle(): void
    {
        $Items = $this->modelRegistry->use('Items');

        foreach ([1, 'item'] as $key) {
            $item = $Items->newEntity([
                'name' => 'Test '.$key,
            ]);

            $this->assertTrue(
                $Items->saveMany([$key => $item])
            );

            $this->assertTrue(
                $Items->exists(['name' => 'Test '.$key])
            );
        }
    }

    public function testUpdate(): void
    {
        $Items = $this->modelRegistry->use('Items');

        $item = $Items->newEntity([
            'name' => 'Test',
        ]);

        $this->assertTrue(
            $Items->save($item)
        );

        $Items->patchEntity($item, [
            'name' => 'Test 2',
        ]);

        $this->assertTrue(
            $Items->save($item)
        );

        $this->assertFalse(
            $item->isDirty()
        );

        $item = $Items->get(1);

        $this->assertInstanceOf(
            Item::class,
            $item
        );

        $this->assertArraysAreIdentical(
            [
                'id' => 1,
                'name' => 'Test 2',
            ],
            $item->toArray()
        );
    }

    public function testUpdateChangedPrimaryKey(): void
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

        $items[0]->set('id', $items[1]->id);
        $items[0]->set('name', 'Updated');

        $caught = null;

        try {
            $Items->save($items[0]);
        } catch (OrmException $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(
            OrmException::class,
            $caught
        );
        $this->assertSame(
            'Primary key values for model `Items` must not be changed.',
            $caught->getMessage()
        );
        $this->assertArraysAreIdentical(
            ['Test 1', 'Test 2'],
            $Items->find(orderBy: ['id' => 'ASC'])
                ->all()
                ->map(static fn(Entity $item): string => $item->name)
                ->toArray()
        );
        $this->assertSame(
            0,
            $this->db->getSavePointLevel()
        );
    }

    public function testUpdateChangedPrimaryKeyInCallback(): void
    {
        $this->expectException(OrmException::class);
        $this->expectExceptionMessageIs('Primary key values for model `Items` must not be changed.');

        $Items = $this->modelRegistry->use('Items');
        $item = $Items->newEntity([
            'name' => 'Test',
        ]);

        $this->assertTrue(
            $Items->save($item)
        );

        $item->set('name', 'Updated');

        $Items->getEventManager()->on('ORM.beforeSave', static function(Event $event, Entity $entity): void {
            $entity->set('id', 10);
        });

        $Items->save($item);
    }

    public function testUpdateIncompleteCompositePrimaryKey(): void
    {
        $this->expectException(OrmException::class);
        $this->expectExceptionMessageIs('Primary key values for model `CompositeItems` must not be null or missing.');

        $CompositeItems = $this->modelRegistry->use('CompositeItems');
        $item = $CompositeItems->newEntity([
            'tenant_id' => 1,
            'name' => 'Updated',
        ], validate: false, new: false);

        $CompositeItems->save($item);
    }

    public function testUpdateMany(): void
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

        $this->assertFalse(
            $items[0]->isDirty()
        );

        $this->assertFalse(
            $items[1]->isDirty()
        );

        $Items->patchEntities($items, [
            [
                'name' => 'Test 3',
            ],
            [
                'name' => 'Test 4',
            ],
        ]);

        $this->assertTrue(
            $Items->saveMany($items)
        );

        $items = $Items->find()->toArray();

        $this->assertArraysAreIdentical(
            [
                [
                    'id' => 1,
                    'name' => 'Test 3',
                ],
                [
                    'id' => 2,
                    'name' => 'Test 4',
                ],
            ],
            array_map(
                static fn(Item $item): array => $item->toArray(),
                $items,
            )
        );
    }

    public function testUpdateManyBatch(): void
    {
        $Items = $this->modelRegistry->use('Items');

        $data = [];

        for ($i = 0; $i < 1000; $i++) {
            $data[] = [
                'name' => 'Test',
            ];
        }

        $items = $Items->newEntities($data);

        $this->assertTrue(
            $Items->saveMany($items)
        );

        $data = [];

        foreach ($items as $i => $item) {
            $data[] = [
                'name' => 'Test '.($i + 1),
            ];
        }

        $Items->patchEntities($items, $data);

        $this->assertTrue(
            $Items->saveMany($items)
        );

        $items = $Items->find()->toArray();

        $this->assertArraysAreIdentical(
            array_map(
                static fn(int $i): string => 'Test '.$i,
                range(1, 1000)
            ),
            array_map(
                static fn(Item $item): string => $item->name,
                $items
            )
        );
    }

    public function testUpdateManyChangedCompositePrimaryKey(): void
    {
        $CompositeItems = $this->modelRegistry->use('CompositeItems');
        $items = $CompositeItems->newEntities([
            [
                'tenant_id' => 1,
                'id' => 1,
                'name' => 'Test 1',
            ],
            [
                'tenant_id' => 2,
                'id' => 1,
                'name' => 'Test 2',
            ],
        ]);

        $this->assertTrue(
            $CompositeItems->saveMany($items)
        );

        $items[0]->set('name', 'Updated 1');
        $items[1]->set('tenant_id', 1);
        $items[1]->set('name', 'Updated 2');

        $caught = null;

        try {
            $CompositeItems->saveMany($items);
        } catch (OrmException $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(
            OrmException::class,
            $caught
        );
        $this->assertArraysAreIdentical(
            ['Test 1', 'Test 2'],
            $CompositeItems->find(orderBy: ['tenant_id' => 'ASC'])
                ->all()
                ->map(static fn(Entity $item): string => $item->get('name'))
                ->toArray()
        );
        $this->assertSame(
            0,
            $this->db->getSavePointLevel()
        );
    }
}
