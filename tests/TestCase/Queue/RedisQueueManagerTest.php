<?php
declare(strict_types=1);

namespace Tests\TestCase\Queue;

use Fyre\Core\Config;
use Fyre\Core\Container;
use Fyre\Queue\Handlers\RedisQueue;
use Fyre\Queue\QueueManager;
use Override;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

use function getenv;

#[RequiresPhpExtension('redis')]
final class RedisQueueManagerTest extends TestCase
{
    protected QueueManager $queueManager;

    public function testBuild(): void
    {
        $this->assertInstanceOf(
            RedisQueue::class,
            $this->queueManager->build([
                'className' => RedisQueue::class,
                'host' => getenv('REDIS_HOST'),
                'password' => getenv('REDIS_PASSWORD'),
                'database' => getenv('REDIS_DATABASE'),
                'port' => getenv('REDIS_PORT'),
            ])
        );
    }

    public function testDebugRedisQueue(): void
    {
        $data = $this->queueManager->use()
            ->__debugInfo();

        $this->assertArraysAreIdentical(
            [
                '[class]' => RedisQueue::class,
                'config' => [
                    'host' => '[*****]',
                    'password' => '',
                    'port' => '[*****]',
                    'database' => '',
                    'prefix' => '',
                    'timeout' => 0,
                    'visibilityTimeout' => 300,
                    'persist' => true,
                    'tls' => false,
                    'ssl' => [
                        'key' => null,
                        'cert' => null,
                        'ca' => null,
                    ],
                    'className' => RedisQueue::class,
                ],
                'connection' => '[Redis]',
                'container' => '[Fyre\Core\Container]',
                'reservations' => '[WeakMap]',
            ],
            $data
        );
    }

    public function testUse(): void
    {
        $handler1 = $this->queueManager->use();
        $handler2 = $this->queueManager->use();

        $this->assertSame($handler1, $handler2);

        $this->assertInstanceOf(
            RedisQueue::class,
            $handler1
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
            ],
        ]);

        $this->queueManager = $container->use(QueueManager::class);
    }
}
