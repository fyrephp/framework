<?php
declare(strict_types=1);

namespace Tests\TestCase\Queue;

use Closure;
use Fyre\Core\Config;
use Fyre\Core\Container;
use Fyre\Queue\FailedMessage;
use Fyre\Queue\Handlers\RedisQueue;
use Fyre\Queue\Message;
use Fyre\Queue\Queue;
use Fyre\Queue\QueueManager;
use InvalidArgumentException;
use Override;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Redis;
use RuntimeException;
use Tests\Mock\Jobs\MockJob;

use function array_key_first;
use function array_keys;
use function getenv;
use function serialize;
use function strlen;
use function time;

#[RequiresPhpExtension('redis')]
final class RedisQueueTest extends TestCase
{
    protected Queue $queue;

    protected QueueManager $queueManager;

    public function testClearReservations(): void
    {
        $this->queue->push(new Message([
            'className' => MockJob::class,
        ]));

        $message = $this->queue->pop();

        $this->assertInstanceOf(Message::class, $message);

        $this->queue->clear();

        $this->assertNull(
            $this->queue->pop()
        );
    }

    public function testDiscardUniqueMessage(): void
    {
        $options = [
            'className' => MockJob::class,
            'unique' => true,
        ];

        $this->assertTrue(
            $this->queue->push(new Message($options))
        );

        $message = $this->queue->pop();

        $this->assertInstanceOf(Message::class, $message);

        $this->queue->discard($message);

        $this->assertTrue(
            $this->queue->push(new Message($options))
        );
    }

    public function testDuplicateDelayedMessages(): void
    {
        $after = time() + 60;
        $options = [
            'className' => MockJob::class,
            'after' => $after,
        ];

        $this->assertTrue(
            $this->queue->push(new Message($options))
        );
        $this->assertTrue(
            $this->queue->push(new Message($options))
        );

        $this->assertSame(
            2,
            $this->queue->stats()['delayed']
        );
    }

    public function testFailRetainsException(): void
    {
        $this->queue->push(new Message([
            'className' => MockJob::class,
            'retry' => false,
        ]));

        $message = $this->queue->pop();

        $this->assertInstanceOf(
            Message::class,
            $message
        );

        $exception = new RuntimeException('Test failure.', 5);
        $failedAt = time();

        $this->assertFalse(
            $this->queue->fail($message, $exception)
        );

        $failures = $this->queue->getFailed();

        $this->assertCount(
            1,
            $failures
        );

        $id = array_key_first($failures);

        $this->assertIsString(
            $id
        );
        $this->assertSame(
            32,
            strlen($id)
        );

        $failure = $failures[$id];

        $this->assertInstanceOf(
            FailedMessage::class,
            $failure
        );
        $this->assertArraysAreIdentical(
            $message->getConfig(),
            $failure->getMessage()->getConfig()
        );
        $this->assertGreaterThanOrEqual(
            $failedAt,
            $failure->getFailedAt()
        );
        $this->assertLessThanOrEqual(
            time(),
            $failure->getFailedAt()
        );
        $this->assertSame(
            RuntimeException::class,
            $failure->getExceptionClass()
        );
        $this->assertSame(
            'Test failure.',
            $failure->getExceptionMessage()
        );
        $this->assertSame(
            5,
            $failure->getExceptionCode()
        );
        $this->assertSame(
            $exception->getFile(),
            $failure->getExceptionFile()
        );
        $this->assertSame(
            $exception->getLine(),
            $failure->getExceptionLine()
        );
        $this->assertSame(
            $exception->getTraceAsString(),
            $failure->getExceptionTrace()
        );
    }

    public function testForgetFailed(): void
    {
        $this->queue->push(new Message([
            'className' => MockJob::class,
            'retry' => false,
        ]));

        $message = $this->queue->pop();

        $this->assertInstanceOf(
            Message::class,
            $message
        );
        $this->assertFalse(
            $this->queue->fail($message)
        );

        $failures = $this->queue->getFailed();
        $id = array_key_first($failures);

        $this->assertIsString(
            $id
        );
        $this->assertNull(
            $failures[$id]->getExceptionClass()
        );
        $this->assertTrue(
            $this->queue->forgetFailed($id)
        );
        $this->assertFalse(
            $this->queue->forgetFailed($id)
        );
        $this->assertArraysAreIdentical(
            [],
            $this->queue->getFailed()
        );
    }

    public function testInvalidVisibilityTimeout(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Redis queue option `visibilityTimeout` must be greater than 0.');

        $this->queueManager->build([
            'className' => RedisQueue::class,
            'visibilityTimeout' => 0,
        ]);
    }

    public function testMalformedFailure(): void
    {
        $connection = Closure::bind(function(): Redis {
            /** @var RedisQueue $this */
            return $this->connection;
        }, $this->queue, RedisQueue::class)();

        $id = '11111111111111111111111111111111';

        $connection->hSet('queue:default:failures', $id, serialize(['invalid']));

        $failures = $this->queue->getFailed();
        $this->queue->forgetFailed($id);

        $this->assertArraysAreIdentical(
            [],
            $failures
        );
    }

    public function testMalformedMessage(): void
    {
        $connection = Closure::bind(function(): Redis {
            /** @var RedisQueue $this */
            return $this->connection;
        }, $this->queue, RedisQueue::class)();

        $connection->lPush('queue:default', 'invalid');

        $this->assertNull(
            $this->queue->pop()
        );

        $this->assertSame(
            0,
            $connection->zCard('queue:default:processing')
        );
    }

    public function testPrefixIsolatesQueues(): void
    {
        $options = $this->queueManager->getConfig('default');

        $this->assertIsArray($options);

        $first = $this->queueManager->build([
            ...$options,
            'prefix' => 'fyre:test:first:',
        ]);
        $second = $this->queueManager->build([
            ...$options,
            'prefix' => 'fyre:test:second:',
        ]);

        $message = new Message([
            'className' => MockJob::class,
            'queue' => 'prefix-test',
            'unique' => true,
            'retry' => false,
        ]);

        try {
            $this->assertTrue($first->push($message));
            $this->assertTrue($second->push($message));
            $this->assertSame(['prefix-test'], $first->queues());
            $this->assertSame(['prefix-test'], $second->queues());
            $this->assertNotContains('prefix-test', $this->queue->queues());

            $reserved = $first->pop('prefix-test');

            $this->assertInstanceOf(Message::class, $reserved);

            $first->fail($reserved);

            $this->assertCount(1, $first->getFailed('prefix-test'));
            $this->assertSame([], $second->getFailed('prefix-test'));
            $this->assertSame(1, $first->stats('prefix-test')['failed']);
            $this->assertSame(1, $second->stats('prefix-test')['queued']);

            $id = array_key_first($first->getFailed('prefix-test'));

            $this->assertIsString($id);
            $this->assertTrue($first->retryFailed($id, 'prefix-test'));

            $first->clear('prefix-test');
            $first->reset('prefix-test');

            $this->assertSame(0, $first->stats('prefix-test')['total']);
            $this->assertSame(1, $second->stats('prefix-test')['total']);
            $this->assertSame(1, $second->stats('prefix-test')['queued']);
        } finally {
            foreach ([$first, $second] as $queue) {
                foreach (array_keys($queue->getFailed('prefix-test')) as $id) {
                    $queue->forgetFailed($id, 'prefix-test');
                }

                $queue->clear('prefix-test');
                $queue->reset('prefix-test');
            }
        }
    }

    public function testReservationReleased(): void
    {
        $this->queue->push(new Message([
            'className' => MockJob::class,
        ]));

        $firstMessage = $this->queue->pop();

        $this->assertInstanceOf(Message::class, $firstMessage);

        $connection = Closure::bind(function(): Redis {
            /** @var RedisQueue $this */
            return $this->connection;
        }, $this->queue, RedisQueue::class)();

        $key = 'queue:default:processing';
        $reservations = $connection->zRange($key, 0, 0);

        $this->assertCount(1, $reservations);

        $connection->zAdd($key, time() - 1, $reservations[0]);

        $secondMessage = $this->queue->pop();

        $this->assertInstanceOf(Message::class, $secondMessage);

        $this->queue->complete($firstMessage);
        $this->queue->complete($secondMessage);

        $this->assertArraysAreIdentical(
            [
                'queued' => 0,
                'delayed' => 0,
                'completed' => 1,
                'failed' => 0,
                'total' => 2,
            ],
            $this->queue->stats()
        );
    }

    public function testRetryBackoff(): void
    {
        $this->queue->push(new Message([
            'className' => MockJob::class,
            'maxRetries' => 2,
            'backoff' => 60,
        ]));

        $message = $this->queue->pop();

        $this->assertInstanceOf(
            Message::class,
            $message
        );
        $this->assertTrue(
            $this->queue->fail($message)
        );

        $this->assertArraysAreIdentical(
            [
                'queued' => 0,
                'delayed' => 1,
                'completed' => 0,
                'failed' => 1,
                'total' => 1,
            ],
            $this->queue->stats()
        );

        $this->assertNull(
            $this->queue->pop()
        );
    }

    public function testRetryFailed(): void
    {
        $this->queue->push(new Message([
            'className' => MockJob::class,
            'maxRetries' => 2,
        ]));

        $message = $this->queue->pop();

        $this->assertInstanceOf(
            Message::class,
            $message
        );
        $this->assertTrue(
            $this->queue->fail($message)
        );

        $message = $this->queue->pop();

        $this->assertInstanceOf(
            Message::class,
            $message
        );
        $this->assertFalse(
            $this->queue->fail($message)
        );

        $failures = $this->queue->getFailed();
        $id = array_key_first($failures);

        $this->assertIsString(
            $id
        );
        $this->assertTrue(
            $this->queue->retryFailed($id)
        );
        $this->assertArraysAreIdentical(
            [],
            $this->queue->getFailed()
        );

        $message = $this->queue->pop();

        $this->assertInstanceOf(
            Message::class,
            $message
        );
        $this->assertTrue(
            $this->queue->fail($message)
        );

        $message = $this->queue->pop();

        $this->assertInstanceOf(
            Message::class,
            $message
        );

        $this->queue->complete($message);
    }

    public function testUniqueMessageLifecycle(): void
    {
        $options = [
            'className' => MockJob::class,
            'unique' => true,
        ];

        $this->assertTrue(
            $this->queue->push(new Message($options))
        );

        $message = $this->queue->pop();

        $this->assertInstanceOf(Message::class, $message);

        $this->assertFalse(
            $this->queue->push(new Message($options))
        );

        $this->queue->complete($message);

        $this->assertTrue(
            $this->queue->push(new Message($options))
        );
    }

    public function testUniqueMessageRetry(): void
    {
        $options = [
            'className' => MockJob::class,
            'maxRetries' => 2,
            'unique' => true,
        ];

        $this->assertTrue(
            $this->queue->push(new Message($options))
        );

        $message = $this->queue->pop();

        $this->assertInstanceOf(Message::class, $message);
        $this->assertTrue(
            $this->queue->fail($message)
        );
        $this->assertFalse(
            $this->queue->push(new Message($options))
        );

        $message = $this->queue->pop();

        $this->assertInstanceOf(Message::class, $message);
        $this->assertFalse(
            $this->queue->fail($message)
        );
        $this->assertTrue(
            $this->queue->push(new Message($options))
        );
    }

    #[Override]
    protected function setUp(): void
    {
        $container = new Container();
        $container->singleton(Config::class);
        $container->singleton(QueueManager::class);

        $container->use(Config::class)->set('Queue', [
            'default' => [
                'className' => RedisQueue::class,
                'host' => getenv('REDIS_HOST'),
                'password' => getenv('REDIS_PASSWORD'),
                'database' => getenv('REDIS_DATABASE'),
                'port' => getenv('REDIS_PORT'),
                'visibilityTimeout' => 1,
            ],
        ]);

        $this->queueManager = $container->use(QueueManager::class);
        $this->queue = $this->queueManager->use();
    }

    #[Override]
    protected function tearDown(): void
    {
        $failures = $this->queue->getFailed();

        foreach (array_keys($failures) as $id) {
            $this->queue->forgetFailed($id);
        }

        $this->queue->clear();
        $this->queue->reset();
    }
}
