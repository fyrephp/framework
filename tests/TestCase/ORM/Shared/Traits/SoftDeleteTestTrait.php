<?php
declare(strict_types=1);

namespace Tests\TestCase\ORM\Shared\Traits;

use Error;
use Fyre\DB\Exceptions\DbException;
use Fyre\DB\Expressions\ConditionExpression;
use Fyre\DB\Query;
use Fyre\Event\Event;
use Fyre\Event\EventManager;
use Fyre\Log\LogManager;
use Fyre\ORM\Entity;
use Fyre\Utility\DateTime\DateTime;
use Generator;
use PHPUnit\Framework\Attributes\Before;
use Tests\Mock\Entities\Address;
use Throwable;

trait SoftDeleteTestTrait
{
    public function testDelete(): void
    {
        $Users = $this->modelRegistry->use('Users');
        $Addresses = $this->modelRegistry->use('Addresses');
        $Posts = $this->modelRegistry->use('Posts');
        $Comments = $this->modelRegistry->use('Comments');

        $Users->Addresses->setDependent(true);
        $Posts->Comments->setDependent(true);

        $user = $Users->newEntity([
            'name' => 'Test',
            'posts' => [
                [
                    'title' => 'Test 1',
                    'content' => 'This is the content.',
                    'comments' => [
                        [
                            'content' => 'This is a comment',
                            'user' => [
                                'name' => 'Test 2',
                            ],
                        ],
                    ],
                ],
                [
                    'title' => 'Test 2',
                    'content' => 'This is the content.',
                    'comments' => [
                        [
                            'content' => 'This is a comment',
                            'user' => [
                                'name' => 'Test 3',
                            ],
                        ],
                    ],
                ],
            ],
            'address' => [
                'suburb' => 'Test',
            ],
        ], associated: [
            'Posts.Comments.Users',
            'Addresses',
        ]);

        $this->assertTrue(
            $Users->save($user)
        );

        $this->assertTrue(
            $Users->delete($user)
        );

        $this->assertInstanceOf(
            DateTime::class,
            $user->deleted
        );

        $this->assertSame(
            2,
            $Users->find()->count()
        );

        $this->assertSame(
            3,
            $Users->find(deleted: true)->count()
        );

        $this->assertSame(
            0,
            $Posts->find()->count()
        );

        $this->assertSame(
            2,
            $Posts->find(deleted: true)->count()
        );

        $this->assertSame(
            0,
            $Addresses->find()->count()
        );

        $this->assertSame(
            1,
            $Addresses->find(deleted: true)->count()
        );

        $this->assertSame(
            0,
            $Comments->find()->count()
        );

        $this->assertSame(
            2,
            $Comments->find(deleted: true)->count()
        );
    }

    public function testDeleteManyOuterRollback(): void
    {
        $Users = $this->modelRegistry->use('Users');
        $users = $Users->newEntities([
            [
                'name' => 'Test 1',
            ],
            [
                'name' => 'Test 2',
            ],
        ]);

        $this->assertTrue(
            $Users->saveMany($users)
        );

        $this->db->begin();

        $this->assertTrue(
            $Users->deleteMany($users)
        );

        $deleted = [];

        foreach ($users as $user) {
            $this->assertInstanceOf(
                DateTime::class,
                $user->deleted
            );

            $deleted[] = $user->deleted;
        }

        $this->db->begin();

        $this->assertTrue(
            $Users->restoreMany($users)
        );

        foreach ($users as $user) {
            $this->assertNull(
                $user->deleted
            );
        }

        $this->db->rollback();

        foreach ($users as $i => $user) {
            $this->assertSame(
                $deleted[$i],
                $user->deleted
            );
        }

        $this->db->rollback();

        foreach ($users as $user) {
            $this->assertNull(
                $user->deleted
            );
            $this->assertFalse(
                $user->isNew()
            );
        }

        $this->assertSame(
            2,
            $Users->find()->count()
        );
    }

    public function testDeletePreservesLaterChanges(): void
    {
        $Users = $this->modelRegistry->use('Users');
        $user = $Users->newEntity([
            'name' => 'Original',
        ]);

        $this->assertTrue(
            $Users->save($user)
        );

        $this->db->begin();

        $this->assertTrue(
            $Users->delete($user)
        );

        $user->name = 'Later';
        $this->db->commit();

        $this->assertInstanceOf(
            DateTime::class,
            $user->deleted
        );
        $this->assertFalse(
            $user->isDirty('deleted')
        );
        $this->assertTrue(
            $user->isDirty('name')
        );
        $this->assertSame(
            'Original',
            $user->getOriginal('name')
        );
        $this->assertSame(
            1,
            $Users->find(deleted: true, conditions: ['name' => 'Original'])->count()
        );
    }

    public function testFindOnlyDeleted(): void
    {
        $Users = $this->modelRegistry->use('Users');

        $users = $Users->newEntities([
            [
                'name' => 'Test 1',
            ],
            [
                'name' => 'Test 2',
            ],
        ]);

        $this->assertTrue(
            $Users->saveMany($users)
        );

        $this->assertTrue(
            $Users->delete($users[0])
        );

        $this->assertArraysAreIdentical(
            [1],
            $Users->findOnlyDeleted(
                conditions: static fn(Query $query): ConditionExpression => $query->expr()
                    ->eq('Users.name', 'Test 1')
            )
                ->all()
                ->map(static fn(Entity $item): int|null => $item->id)
                ->toArray()
        );
    }

    public function testFindWithDeleted(): void
    {
        $Users = $this->modelRegistry->use('Users');

        $users = $Users->newEntities([
            [
                'name' => 'Test 1',
            ],
            [
                'name' => 'Test 2',
            ],
        ]);

        $this->assertTrue(
            $Users->saveMany($users)
        );

        $this->assertTrue(
            $Users->delete($users[0])
        );

        $this->assertSame(
            1,
            $Users->find()->count()
        );

        $this->assertArraysAreIdentical(
            [1, 2],
            $Users->findWithDeleted(
                conditions: static fn(Query $query): ConditionExpression => $query->expr()
                    ->gt('Users.id', 0)
            )
                ->orderBy(['id' => 'ASC'])
                ->all()
                ->map(static fn(Entity $item): int|null => $item->id)
                ->toArray()
        );
    }

    public function testInnerJoinWithFiltersSoftDeletedRelations(): void
    {
        $Users = $this->modelRegistry->use('Users');
        $Posts = $this->modelRegistry->use('Posts');

        $users = $Users->newEntities([
            [
                'name' => 'Test 1',
                'posts' => [
                    [
                        'title' => 'Test 1',
                        'content' => 'This is the content.',
                    ],
                ],
            ],
            [
                'name' => 'Test 2',
                'posts' => [
                    [
                        'title' => 'Test 2',
                        'content' => 'This is the content.',
                    ],
                ],
            ],
        ], associated: ['Posts']);

        $this->assertTrue(
            $Users->saveMany($users)
        );

        $this->assertTrue(
            $Posts->delete($users[0]->posts[0])
        );

        $this->assertArraysAreIdentical(
            [2],
            $Users->find()
                ->innerJoinWith('Posts')
                ->orderBy(['Users.id' => 'ASC'])
                ->all()
                ->map(static fn(Entity $user): int|null => $user->id)
                ->toArray()
        );

        $this->assertArraysAreIdentical(
            [1, 2],
            $Users->findWithDeleted()
                ->innerJoinWith('Posts')
                ->orderBy(['Users.id' => 'ASC'])
                ->all()
                ->map(static fn(Entity $user): int|null => $user->id)
                ->toArray()
        );
    }

    public function testJoinContainFiltersSoftDeletedRelations(): void
    {
        $Users = $this->modelRegistry->use('Users');
        $Addresses = $this->modelRegistry->use('Addresses');

        $users = $Users->newEntities([
            [
                'name' => 'Test 1',
                'address' => [
                    'suburb' => 'Test 1',
                ],
            ],
            [
                'name' => 'Test 2',
                'address' => [
                    'suburb' => 'Test 2',
                ],
            ],
        ], associated: ['Addresses']);

        $this->assertTrue(
            $Users->saveMany($users)
        );

        $this->assertTrue(
            $Addresses->delete($users[0]->address)
        );

        $this->assertArraysAreIdentical(
            [null, 2],
            $Users->find()
                ->contain('Addresses')
                ->orderBy(['Users.id' => 'ASC'])
                ->all()
                ->map(static function(Entity $user): int|null {
                    $address = $user->get('address');

                    return $address instanceof Address ? $address->id : null;
                })
                ->toArray()
        );

        $this->assertArraysAreIdentical(
            [1, 2],
            $Users->findWithDeleted()
                ->contain('Addresses')
                ->orderBy(['Users.id' => 'ASC'])
                ->all()
                ->map(static function(Entity $user): int|null {
                    $address = $user->get('address');

                    return $address instanceof Address ? $address->id : null;
                })
                ->toArray()
        );
    }

    public function testPurge(): void
    {
        $Users = $this->modelRegistry->use('Users');

        $users = $Users->newEntities([
            [
                'name' => 'Test 1',
            ],
            [
                'name' => 'Test 2',
            ],
        ]);

        $this->assertTrue(
            $Users->saveMany($users)
        );

        $this->assertTrue(
            $Users->purge($users[0])
        );

        $this->assertArraysAreIdentical(
            [2],
            $Users->find(deleted: true)
                ->all()
                ->map(static fn(Entity $item): int|null => $item->id)
                ->toArray()
        );
    }

    public function testPurgeMany(): void
    {
        $Users = $this->modelRegistry->use('Users');

        $users = $Users->newEntities([
            [
                'name' => 'Test 1',
            ],
            [
                'name' => 'Test 2',
            ],
        ]);

        $this->assertTrue(
            $Users->saveMany($users)
        );

        $this->assertTrue(
            $Users->purgeMany($users)
        );

        $this->assertSame(
            0,
            $Users->find(deleted: true)->count()
        );
    }

    public function testRestore(): void
    {
        $Users = $this->modelRegistry->use('Users');

        $users = $Users->newEntities([
            [
                'name' => 'Test 1',
            ],
            [
                'name' => 'Test 2',
            ],
        ]);

        $this->assertTrue(
            $Users->saveMany($users)
        );

        $this->assertTrue(
            $Users->delete($users[0])
        );

        $this->assertSame(
            1,
            $Users->find()->count()
        );

        $this->assertSame(
            2,
            $Users->find(deleted: true)->count()
        );

        $this->assertTrue(
            $Users->restore($users[0])
        );

        $this->assertNull(
            $users[0]->deleted
        );

        $this->assertSame(
            2,
            $Users->find()->count()
        );

        $this->assertArraysAreIdentical(
            [1, 2],
            $Users->find()
                ->orderBy(['id' => 'ASC'])
                ->all()
                ->map(static fn(Entity $item): int|null => $item->id)
                ->toArray()
        );
    }

    public function testRestoreCommitExceptionRollsBack(): void
    {
        $Users = $this->modelRegistry->use('Users');
        $user = $Users->newEntity([
            'name' => 'Test',
        ]);

        $this->assertTrue(
            $Users->save($user)
        );
        $this->assertTrue(
            $Users->delete($user)
        );

        $deleted = $user->deleted;
        $connection = $this->getStubBuilder($this->db::class)
            ->setConstructorArgs([
                $this->container,
                $this->container->use(EventManager::class),
                $this->container->use(LogManager::class),
                $this->db->getConfig(),
            ])
            ->onlyMethods(['transCommit'])
            ->getStub();

        $exception = new DbException('Commit failed.');
        $connection->method('transCommit')->willThrowException($exception);

        try {
            $Users->setConnection($connection);
            $caught = null;

            try {
                $Users->restore($user, dependents: false);
            } catch (Throwable $e) {
                $caught = $e;
            }

            $this->assertSame(
                $exception,
                $caught
            );
            $this->assertSame(
                0,
                $connection->getSavePointLevel()
            );
            $this->assertFalse(
                $connection->inTransaction()
            );
            $this->assertSame(
                0,
                $Users->find()->count()
            );
            $this->assertSame(
                $deleted,
                $user->deleted
            );
        } finally {
            while ($connection->getSavePointLevel() > 0) {
                $connection->rollback();
            }

            $connection->disconnect();
        }
    }

    public function testRestoreDependents(): void
    {
        $Users = $this->modelRegistry->use('Users');
        $Posts = $this->modelRegistry->use('Posts');
        $Comments = $this->modelRegistry->use('Comments');

        $Posts->Comments->setDependent(true);

        $user = $Users->newEntity([
            'name' => 'Test',
            'posts' => [
                [
                    'title' => 'Test',
                    'comments' => [
                        [
                            'content' => 'Test',
                        ],
                    ],
                ],
            ],
        ], associated: ['Posts.Comments']);

        $this->assertTrue(
            $Users->save($user)
        );
        $this->assertTrue(
            $Users->delete($user)
        );
        $this->assertSame(
            0,
            $Comments->find()->count()
        );

        $this->assertTrue(
            $Users->restore($user)
        );
        $this->assertSame(
            1,
            $Users->find()->count()
        );
        $this->assertSame(
            1,
            $Posts->find()->count()
        );
        $this->assertSame(
            1,
            $Comments->find()->count()
        );
        $this->assertSame(
            0,
            $this->db->getSavePointLevel()
        );
        $this->assertNull(
            $user->deleted
        );
    }

    public function testRestoreExceptionRollsBackNestedTransaction(): void
    {
        $Users = $this->modelRegistry->use('Users');
        $Posts = $this->modelRegistry->use('Posts');
        $user = $Users->newEntity([
            'name' => 'Test',
            'posts' => [
                [
                    'title' => 'Test',
                ],
            ],
        ]);

        $this->assertTrue(
            $Users->save($user)
        );
        $this->assertTrue(
            $Users->delete($user)
        );

        $deleted = $user->deleted;
        $exception = new Error('Restore failed.');
        $commits = [];

        $this->db->begin();
        $this->db->afterCommit(static function() use (&$commits): void {
            $commits[] = 'outer';
        });

        $Posts->getEventManager()->on('ORM.afterSave', function() use (&$commits): void {
            $this->db->afterCommit(static function() use (&$commits): void {
                $commits[] = 'restore';
            });
        });
        $Users->getEventManager()->on('ORM.afterSave', static function() use ($exception): never {
            throw $exception;
        });

        $caught = null;

        try {
            $Users->restore($user);
        } catch (Throwable $e) {
            $caught = $e;
        }

        $this->assertSame(
            $exception,
            $caught
        );
        $this->assertSame(
            1,
            $this->db->getSavePointLevel()
        );
        $this->assertTrue(
            $this->db->inTransaction()
        );
        $this->assertSame(
            0,
            $Users->find()->count()
        );
        $this->assertSame(
            0,
            $Posts->find()->count()
        );
        $this->assertSame(
            $deleted,
            $user->deleted
        );

        $this->db->commit();

        $this->assertSame(
            ['outer'],
            $commits
        );
    }

    public function testRestoreFailureRollsBack(): void
    {
        $Users = $this->modelRegistry->use('Users');
        $Posts = $this->modelRegistry->use('Posts');
        $user = $Users->newEntity([
            'name' => 'Test',
            'posts' => [
                [
                    'title' => 'Test',
                ],
            ],
        ]);

        $this->assertTrue(
            $Users->save($user)
        );
        $this->assertTrue(
            $Users->delete($user)
        );

        $deleted = $user->deleted;

        $Users->getEventManager()->on('ORM.beforeSave', static function(Event $event): false {
            $event->stopPropagation();

            return false;
        });

        $this->assertFalse(
            $Users->restore($user)
        );
        $this->assertSame(
            0,
            $this->db->getSavePointLevel()
        );
        $this->assertFalse(
            $this->db->inTransaction()
        );
        $this->assertSame(
            0,
            $Users->find()->count()
        );
        $this->assertSame(
            0,
            $Posts->find()->count()
        );
        $this->assertSame(
            $deleted,
            $user->deleted
        );
    }

    public function testRestoreMany(): void
    {
        $Users = $this->modelRegistry->use('Users');

        $users = $Users->newEntities([
            [
                'name' => 'Test 1',
            ],
            [
                'name' => 'Test 2',
            ],
        ]);

        $this->assertTrue(
            $Users->saveMany($users)
        );

        $this->assertTrue(
            $Users->deleteMany($users)
        );

        $this->assertSame(
            0,
            $Users->find()->count()
        );

        $this->assertSame(
            2,
            $Users->find(deleted: true)->count()
        );

        $this->assertTrue(
            $Users->restoreMany($users)
        );

        $this->assertSame(
            2,
            $Users->find()->count()
        );

        $this->assertArraysAreIdentical(
            [1, 2],
            $Users->find()
                ->orderBy(['id' => 'ASC'])
                ->all()
                ->map(static fn(Entity $item): int|null => $item->id)
                ->toArray()
        );
    }

    public function testRestoreManyGenerator(): void
    {
        $Users = $this->modelRegistry->use('Users');

        $users = $Users->newEntities([
            [
                'name' => 'Test 1',
            ],
            [
                'name' => 'Test 2',
            ],
        ]);

        $this->assertTrue(
            $Users->saveMany($users)
        );

        $this->assertTrue(
            $Users->deleteMany($users)
        );

        $generator = static function() use ($users): Generator {
            yield from [$users[0]];
            yield from [$users[1]];
        };

        $this->assertTrue(
            $Users->restoreMany($generator())
        );

        $this->assertSame(
            2,
            $Users->find()->count()
        );
    }

    public function testRestoreOuterRollback(): void
    {
        $Users = $this->modelRegistry->use('Users');
        $user = $Users->newEntity([
            'name' => 'Test',
        ]);

        $this->assertTrue(
            $Users->save($user)
        );
        $this->assertTrue(
            $Users->delete($user)
        );

        $deleted = $user->deleted;
        $this->db->begin();

        $this->assertTrue(
            $Users->restore($user)
        );

        $this->db->rollback();

        $this->assertSame(
            $deleted,
            $user->deleted
        );
        $this->assertSame(
            0,
            $Users->find()->count()
        );
        $this->assertSame(
            1,
            $Users->find(deleted: true)->count()
        );
    }

    public function testRestoreWithoutDependents(): void
    {
        $Users = $this->modelRegistry->use('Users');
        $Posts = $this->modelRegistry->use('Posts');
        $user = $Users->newEntity([
            'name' => 'Test',
            'posts' => [
                [
                    'title' => 'Test',
                ],
            ],
        ]);

        $this->assertTrue(
            $Users->save($user)
        );
        $this->assertTrue(
            $Users->delete($user)
        );

        $user = $Users->findWithDeleted()->first();

        $this->assertInstanceOf(
            Entity::class,
            $user
        );
        $this->assertTrue(
            $Users->restore($user, dependents: false)
        );
        $this->assertSame(
            1,
            $Users->find()->count()
        );
        $this->assertSame(
            0,
            $Posts->find()->count()
        );
    }

    #[Before(-1)]
    protected function changeNamespace(): void
    {
        $this->modelRegistry->clearNamespaces();
        $this->modelRegistry->addNamespace('Tests\Mock\Models\ORM\SoftDelete');
    }
}
