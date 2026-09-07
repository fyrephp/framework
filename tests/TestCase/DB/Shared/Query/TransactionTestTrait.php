<?php
declare(strict_types=1);

namespace Tests\TestCase\DB\Shared\Query;

use Error;
use Exception;
use Fyre\DB\Connection;
use Fyre\DB\Exceptions\DbException;
use Fyre\Event\EventManager;
use Fyre\Log\LogManager;
use Throwable;

trait TransactionTestTrait
{
    public function testAfterCommit(): void
    {
        $this->db->begin();

        $test = 0;
        $this->db->afterCommit(function() use (&$test) {
            $this->assertFalse(
                $this->db->inTransaction()
            );

            $test++;
        });

        $this->assertSame(
            0,
            $test
        );

        $this->db->commit();

        $this->assertSame(
            1,
            $test
        );
    }

    public function testAfterCommitDeep(): void
    {
        $this->db->begin();
        $this->db->begin();

        $test = 0;
        $this->db->afterCommit(function() use (&$test) {
            $this->assertFalse(
                $this->db->inTransaction()
            );

            $test++;
        });

        $this->db->commit();

        $this->assertSame(
            0,
            $test
        );

        $this->db->commit();

        $this->assertSame(
            1,
            $test
        );
    }

    public function testAfterCommitKey(): void
    {
        $this->db->begin();

        $test = 0;
        $this->db->afterCommit(static function() use (&$test) {
            $test++;
        }, key: 'test');
        $this->db->afterCommit(static function() use (&$test) {
            $test++;
        }, key: 'test');

        $this->db->commit();

        $this->assertSame(
            1,
            $test
        );
    }

    public function testAfterCommitKeyInnerCommit(): void
    {
        $callbacks = [];

        $this->db->begin();
        $this->db->afterCommit(static function() use (&$callbacks): void {
            $callbacks[] = 'outer';
        }, key: 'test');

        $this->db->begin();
        $this->db->afterCommit(static function() use (&$callbacks): void {
            $callbacks[] = 'inner';
        }, key: 'test');
        $this->db->commit();

        $this->assertSame(
            [],
            $callbacks
        );

        $this->db->commit();

        $this->assertSame(
            ['inner'],
            $callbacks
        );
    }

    public function testAfterCommitKeyInnerRollback(): void
    {
        $callbacks = [];

        $this->db->begin();
        $this->db->afterCommit(static function() use (&$callbacks): void {
            $callbacks[] = 'outer';
        }, 2, key: 'test');
        $this->db->afterCommit(static function() use (&$callbacks): void {
            $callbacks[] = 'first';
        });

        $this->db->begin();
        $this->db->afterCommit(static function() use (&$callbacks): void {
            $callbacks[] = 'inner';
        }, key: 'test');
        $this->db->afterCommit(static function() use (&$callbacks): void {
            $callbacks[] = 'replacement';
        }, key: 'test');
        $this->db->rollback();

        $this->assertSame(
            [],
            $callbacks
        );

        $this->db->commit();

        $this->assertSame(
            ['first', 'outer'],
            $callbacks
        );
    }

    public function testAfterCommitKeyInnerRollbackAfterRelease(): void
    {
        $callbacks = [];

        $this->db->begin();
        $this->db->afterCommit(static function() use (&$callbacks): void {
            $callbacks[] = 'outer';
        }, key: 'test');

        $this->db->begin();
        $this->db->afterCommit(static function() use (&$callbacks): void {
            $callbacks[] = 'inner';
        }, key: 'test');

        $this->db->begin();
        $this->db->afterCommit(static function() use (&$callbacks): void {
            $callbacks[] = 'deep';
        }, key: 'test');
        $this->db->commit();
        $this->db->rollback();
        $this->db->commit();

        $this->assertSame(
            ['outer'],
            $callbacks
        );
    }

    public function testAfterCommitKeyOuterRollback(): void
    {
        $callbacks = [];

        $this->db->begin();
        $this->db->afterCommit(static function() use (&$callbacks): void {
            $callbacks[] = 'outer';
        }, key: 'test');

        $this->db->begin();
        $this->db->afterCommit(static function() use (&$callbacks): void {
            $callbacks[] = 'inner';
        }, key: 'test');
        $this->db->commit();
        $this->db->rollback();

        $this->db->begin();
        $this->db->commit();

        $this->assertSame(
            [],
            $callbacks
        );
    }

    public function testAfterCommitPriority(): void
    {
        $this->db->begin();

        $test = [];
        $this->db->afterCommit(static function() use (&$test) {
            $test[] = 1;
        }, 2);
        $this->db->afterCommit(static function() use (&$test) {
            $test[] = 2;
        }, 1);

        $this->db->commit();

        $this->assertArraysAreIdentical(
            [2, 1],
            $test
        );
    }

    public function testAfterCommitRollback(): void
    {
        $this->db->begin();

        $test = 0;
        $this->db->afterCommit(static function() use (&$test) {
            $test++;
        });

        $this->db->rollback();

        $this->assertSame(
            0,
            $test
        );

        $this->db->begin();
        $this->db->commit();

        $this->assertSame(
            0,
            $test
        );
    }

    public function testAfterCommitRollbackDeep(): void
    {
        $this->db->begin();

        $test = 0;
        $this->db->afterCommit(function() use (&$test) {
            $this->assertFalse(
                $this->db->inTransaction()
            );

            $test++;
        });

        $this->db->begin();

        $this->db->afterCommit(static function() use (&$test) {
            $test++;
        });

        $this->db->rollback();

        $this->assertSame(
            0,
            $test
        );

        $this->db->commit();

        $this->assertSame(
            1,
            $test
        );
    }

    public function testAfterCommitWithoutTransaction(): void
    {
        $test = 0;
        $this->db->afterCommit(function() use (&$test) {
            $this->assertFalse(
                $this->db->inTransaction()
            );

            $test++;
        });

        $this->assertSame(
            1,
            $test
        );
    }

    public function testAfterRollback(): void
    {
        $this->db->begin();

        $levels = [];
        $this->db->afterRollback(function() use (&$levels): void {
            $levels[] = $this->db->getSavePointLevel();
        });

        $this->assertSame(
            [],
            $levels
        );

        $this->db->rollback();

        $this->assertSame(
            [0],
            $levels
        );

        $this->db->begin();
        $this->db->rollback();

        $this->assertSame(
            [0],
            $levels
        );
    }

    public function testAfterRollbackCommit(): void
    {
        $this->db->begin();

        $called = false;
        $this->db->afterRollback(static function() use (&$called): void {
            $called = true;
        });

        $this->db->commit();
        $this->db->begin();
        $this->db->rollback();

        $this->assertFalse(
            $called
        );
    }

    public function testAfterRollbackDeep(): void
    {
        $calls = [];

        $this->db->begin();
        $this->db->afterRollback(static function() use (&$calls): void {
            $calls[] = 'outer';
        });

        $this->db->begin();
        $this->db->afterRollback(static function() use (&$calls): void {
            $calls[] = 'inner';
        });

        $this->db->rollback();

        $this->assertSame(
            ['inner'],
            $calls
        );

        $this->db->rollback();

        $this->assertSame(
            ['inner', 'outer'],
            $calls
        );
    }

    public function testAfterRollbackException(): void
    {
        $this->db->begin();

        $called = false;
        $this->db->afterRollback(static function() use (&$called): void {
            $called = true;
        });
        $this->db->afterRollback(static function(): never {
            throw new Exception('Rollback callback failed.');
        });

        $this->db->rollback();

        $this->assertTrue(
            $called
        );
        $this->assertSame(
            0,
            $this->db->getSavePointLevel()
        );
    }

    public function testAfterRollbackReleasedSavepoint(): void
    {
        $calls = [];

        $this->db->begin();
        $this->db->afterRollback(static function() use (&$calls): void {
            $calls[] = 'outer';
        });

        $this->db->begin();
        $this->db->afterRollback(static function() use (&$calls): void {
            $calls[] = 'inner';
        });

        $this->db->begin();
        $this->db->afterRollback(static function() use (&$calls): void {
            $calls[] = 'deep';
        });

        $this->db->commit();

        $this->assertSame(
            [],
            $calls
        );

        $this->db->rollback();

        $this->assertSame(
            ['deep', 'inner'],
            $calls
        );

        $this->db->commit();
        $this->db->begin();
        $this->db->rollback();

        $this->assertSame(
            ['deep', 'inner'],
            $calls
        );
    }

    public function testAfterRollbackReverseOrder(): void
    {
        $this->db->begin();

        $calls = [];
        $this->db->afterRollback(static function() use (&$calls): void {
            $calls[] = 1;
        });
        $this->db->afterRollback(static function() use (&$calls): void {
            $calls[] = 2;
        });

        $this->db->rollback();

        $this->assertSame(
            [2, 1],
            $calls
        );
    }

    public function testAfterRollbackWithoutTransaction(): void
    {
        $called = false;
        $this->db->afterRollback(static function() use (&$called): void {
            $called = true;
        });

        $this->db->begin();
        $this->db->rollback();

        $this->assertFalse(
            $called
        );
    }

    public function testInTransaction(): void
    {
        $this->db->begin();

        $this->assertTrue(
            $this->db->inTransaction()
        );

        $this->db->rollback();
    }

    public function testTransactionalBeginExceptionPreservesOuterTransaction(): void
    {
        $container = static::buildContainer();
        $connection = $this->getStubBuilder($this->db::class)
            ->setConstructorArgs([
                $container,
                $container->use(EventManager::class),
                $container->use(LogManager::class),
                $this->db->getConfig(),
            ])
            ->onlyMethods(['transSavepoint'])
            ->getStub();

        $exception = new DbException('Savepoint failed.');

        $connection->method('transSavepoint')
            ->willThrowException($exception);

        try {
            $connection->begin();
            $connection->insert()
                ->into('test')
                ->values([
                    [
                        'name' => 'Test 1',
                    ],
                ])
                ->execute();

            $commits = 0;
            $connection->afterCommit(static function() use (&$commits): void {
                $commits++;
            });

            $called = false;
            $caught = null;

            try {
                $connection->transactional(static function(Connection $db) use (&$called): void {
                    $called = true;
                });
            } catch (Throwable $e) {
                $caught = $e;
            }

            $this->assertSame(
                $exception,
                $caught
            );

            $this->assertFalse(
                $called
            );

            $this->assertSame(
                1,
                $connection->getSavePointLevel()
            );

            $this->assertTrue(
                $connection->inTransaction()
            );

            $this->assertSame(
                0,
                $commits
            );

            $connection->commit();

            $this->assertSame(
                1,
                $commits
            );

            $this->assertFalse(
                $connection->inTransaction()
            );

            $this->assertSame(
                ['name' => 'Test 1'],
                $connection->select(['name'])
                    ->from('test')
                    ->execute()
                    ->first()
            );
        } finally {
            while ($connection->getSavePointLevel() > 0) {
                $connection->rollback();
            }

            $connection->disconnect();
        }
    }

    public function testTransactionalCommit(): void
    {
        $this->assertTrue(
            $this->db->transactional(static function(Connection $db) {
                $db->insert()
                    ->into('test')
                    ->values([
                        [
                            'name' => 'Test 1',
                        ],
                        [
                            'name' => 'Test 2',
                        ],
                    ])
                    ->execute();
            })
        );

        $this->assertArraysAreIdentical(
            [
                [
                    'id' => 1,
                    'name' => 'Test 1',
                ],
                [
                    'id' => 2,
                    'name' => 'Test 2',
                ],
            ],
            $this->db->select()
                ->from('test')
                ->execute()
                ->all()
        );
    }

    public function testTransactionalCommitExceptionRollsBack(): void
    {
        $container = static::buildContainer();
        $connection = $this->getStubBuilder($this->db::class)
            ->setConstructorArgs([
                $container,
                $container->use(EventManager::class),
                $container->use(LogManager::class),
                $this->db->getConfig(),
            ])
            ->onlyMethods(['transCommit'])
            ->getStub();

        $exception = new DbException('Commit failed.');

        $connection->method('transCommit')
            ->willThrowException($exception);

        try {
            $commits = 0;
            $rollbacks = 0;
            $caught = null;

            try {
                $connection->transactional(static function(Connection $db) use (&$commits, &$rollbacks): void {
                    $db->insert()
                        ->into('test')
                        ->values([
                            [
                                'name' => 'Test 1',
                            ],
                        ])
                        ->execute();

                    $db->afterCommit(static function() use (&$commits): void {
                        $commits++;
                    });
                    $db->afterRollback(static function() use (&$rollbacks): void {
                        $rollbacks++;
                    });
                });
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
                $connection->select()
                    ->from('test')
                    ->count()
            );

            $this->assertSame(
                0,
                $commits
            );
            $this->assertSame(
                1,
                $rollbacks
            );
        } finally {
            while ($connection->getSavePointLevel() > 0) {
                $connection->rollback();
            }

            $connection->disconnect();
        }
    }

    public function testTransactionalCommitExceptionRollsBackNestedTransaction(): void
    {
        $container = static::buildContainer();
        $connection = $this->getStubBuilder($this->db::class)
            ->setConstructorArgs([
                $container,
                $container->use(EventManager::class),
                $container->use(LogManager::class),
                $this->db->getConfig(),
            ])
            ->onlyMethods(['transRelease'])
            ->getStub();

        $exception = new Error('Savepoint release failed.');

        $connection->method('transRelease')
            ->willThrowException($exception);

        try {
            $connection->begin();
            $connection->insert()
                ->into('test')
                ->values([
                    [
                        'name' => 'Test 1',
                    ],
                ])
                ->execute();

            $commits = [];
            $connection->afterCommit(static function() use (&$commits): void {
                $commits[] = 'outer';
            });

            $caught = null;

            try {
                $connection->transactional(static function(Connection $db) use (&$commits): void {
                    $db->insert()
                        ->into('test')
                        ->values([
                            [
                                'name' => 'Test 2',
                            ],
                        ])
                        ->execute();

                    $db->afterCommit(static function() use (&$commits): void {
                        $commits[] = 'inner';
                    });
                });
            } catch (Throwable $e) {
                $caught = $e;
            }

            $this->assertSame(
                $exception,
                $caught
            );

            $this->assertSame(
                1,
                $connection->getSavePointLevel()
            );

            $this->assertTrue(
                $connection->inTransaction()
            );

            $this->assertSame(
                [],
                $commits
            );

            $connection->commit();

            $this->assertSame(
                ['outer'],
                $commits
            );

            $this->assertFalse(
                $connection->inTransaction()
            );

            $this->assertSame(
                [
                    [
                        'name' => 'Test 1',
                    ],
                ],
                $connection->select(['name'])
                    ->from('test')
                    ->execute()
                    ->all()
            );
        } finally {
            while ($connection->getSavePointLevel() > 0) {
                $connection->rollback();
            }

            $connection->disconnect();
        }
    }

    public function testTransactionalRollback(): void
    {
        $this->assertFalse(
            $this->db->transactional(static function(Connection $db) {
                $db->insert()
                    ->into('test')
                    ->values([
                        [
                            'name' => 'Test 1',
                        ],
                        [
                            'name' => 'Test 2',
                        ],
                    ])
                    ->execute();

                return false;
            })
        );

        $this->assertArraysAreIdentical(
            [],
            $this->db->select()
                ->from('test')
                ->execute()
                ->all()
        );
    }

    public function testTransactionalRollbackException(): void
    {
        try {
            $this->db->transactional(static function(Connection $db) {
                $db->insert()
                    ->into('test')
                    ->values([
                        [
                            'name' => 'Test 1',
                        ],
                        [
                            'name' => 'Test 2',
                        ],
                    ])
                    ->execute();

                throw new Exception();
            });
        } catch (Exception $e) {
        }

        $this->assertArraysAreIdentical(
            [],
            $this->db->select()
                ->from('test')
                ->execute()
                ->all()
        );
    }

    public function testTransactionalRollbackExceptionThrown(): void
    {
        $this->expectException(Exception::class);

        $this->db->transactional(static function(Connection $db) {
            throw new Exception();
        });
    }

    public function testTransactionCommit(): void
    {
        $this->db->begin();

        $this->db->insert()
            ->into('test')
            ->values([
                [
                    'name' => 'Test 1',
                ],
                [
                    'name' => 'Test 2',
                ],
            ])
            ->execute();

        $this->db->commit();

        $this->assertArraysAreIdentical(
            [
                [
                    'id' => 1,
                    'name' => 'Test 1',
                ],
                [
                    'id' => 2,
                    'name' => 'Test 2',
                ],
            ],
            $this->db->select()
                ->from('test')
                ->execute()
                ->all()
        );
    }

    public function testTransactionNested(): void
    {
        $this->db->begin();

        $this->db->insert()
            ->into('test')
            ->values([
                [
                    'name' => 'Test 1',
                ],
            ])
            ->execute();

        $this->db->begin();

        $this->db->insert()
            ->into('test')
            ->values([
                [
                    'name' => 'Test 2',
                ],
            ])
            ->execute();

        $this->db->rollback();

        $this->db->commit();

        $this->assertArraysAreIdentical(
            [
                [
                    'id' => 1,
                    'name' => 'Test 1',
                ],
            ],
            $this->db->select()
                ->from('test')
                ->execute()
                ->all()
        );
    }

    public function testTransactionNestedRollback(): void
    {
        $this->db->begin();

        $this->db->insert()
            ->into('test')
            ->values([
                [
                    'name' => 'Test 1',
                ],
            ])
            ->execute();

        $this->db->begin();

        $this->db->insert()
            ->into('test')
            ->values([
                [
                    'name' => 'Test 2',
                ],
            ])
            ->execute();

        $this->db->rollback();

        $this->db->rollback();

        $this->assertArraysAreIdentical(
            [],
            $this->db->select()
                ->from('test')
                ->execute()
                ->all()
        );
    }

    public function testTransactionRollback(): void
    {
        $this->db->begin();

        $this->db->insert()
            ->into('test')
            ->values([
                [
                    'name' => 'Test 1',
                ],
                [
                    'name' => 'Test 2',
                ],
            ])
            ->execute();

        $this->db->rollback();

        $this->assertArraysAreIdentical(
            [],
            $this->db->select()
                ->from('test')
                ->execute()
                ->all()
        );
    }
}
