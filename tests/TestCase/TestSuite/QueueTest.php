<?php
declare(strict_types=1);

namespace Tests\TestCase\TestSuite;

use Fyre\Core\Config;
use Fyre\Core\Engine;
use Fyre\Core\Loader;
use Fyre\Queue\Handlers\RedisQueue;
use Fyre\TestSuite\Constraint\Queue\JobQueued;
use Fyre\TestSuite\Queue\Handlers\TestQueue;
use Fyre\TestSuite\TestCase;
use Fyre\TestSuite\Traits\QueueTestTrait;
use Override;
use Tests\Mock\Jobs\MockJob;

final class QueueTest extends TestCase
{
    use QueueTestTrait;

    public function testCapturesNamedQueues(): void
    {
        $this->assertNoJobsQueued();

        $this->queueManager->push(MockJob::class, ['test' => 1]);
        $this->queueManager->push(MockJob::class, ['test' => 2], [
            'config' => 'secondary',
            'queue' => 'emails',
            'unique' => true,
        ]);

        $this->assertJobCount(2);
        $this->assertJobQueued(MockJob::class, ['test' => 1]);
        $this->assertJobQueued(MockJob::class, ['test' => 2], [
            'config' => 'secondary',
            'queue' => 'emails',
            'unique' => true,
        ]);
        $this->assertNull($this->queueManager->use()->pop());
        $this->assertFalse(
            new JobQueued(MockJob::class, ['test' => 3])->evaluate($this->getQueuedMessages(), '', true)
        );
        $this->assertFalse(
            new JobQueued(MockJob::class, null, ['queue' => 'missing'])->evaluate($this->getQueuedMessages(), '', true)
        );
    }

    public function testRestoresConfiguration(): void
    {
        $config = $this->queueConfigs;
        $this->queueManager->push(MockJob::class);

        $this->tearDownQueueHandlers();

        $this->assertSame($config, $this->queueManager->getConfig());
        $this->assertNoJobsQueued();

        $this->setupQueueHandlers();

        $this->assertInstanceOf(TestQueue::class, $this->queueManager->use());
    }

    public function testUnconfiguredQueue(): void
    {
        $this->tearDownQueueHandlers();
        $this->queueManager->clear();

        $this->setupQueueHandlers();
        $this->queueManager->push(MockJob::class);

        $this->assertJobCount(1);
    }

    #[Override]
    protected function setUp(): void
    {
        $app = new Engine(new Loader());

        $app->use(Config::class)->set('Queue', [
            'default' => [
                'className' => RedisQueue::class,
            ],
            'secondary' => [
                'className' => RedisQueue::class,
            ],
        ]);

        Engine::setInstance($app);

        parent::setUp();
    }
}
