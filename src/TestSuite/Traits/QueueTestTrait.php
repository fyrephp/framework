<?php
declare(strict_types=1);

namespace Fyre\TestSuite\Traits;

use Fyre\Queue\Message;
use Fyre\Queue\QueueManager;
use Fyre\TestSuite\Constraint\Queue\JobQueued;
use Fyre\TestSuite\Queue\Handlers\TestQueue;
use Fyre\TestSuite\TestCase;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

/**
 * Captures queued jobs and restores queue configuration after each test.
 *
 * @phpstan-require-extends TestCase
 */
trait QueueTestTrait
{
    /**
     * @var array<string, array<string, mixed>>
     */
    protected array $queueConfigs = [];

    protected QueueManager $queueManager;

    /**
     * Assert the number of dispatched jobs.
     *
     * @param int $count The expected count.
     * @param string $message The failure message.
     */
    public function assertJobCount(int $count, string $message = ''): void
    {
        $this->assertCount($count, $this->getQueuedMessages(), $message);
    }

    /**
     * Assert a job was dispatched with matching arguments and options.
     *
     * @param class-string $className The job class.
     * @param array<string, mixed>|null $arguments The arguments, or null to accept any.
     * @param array<string, mixed> $options The message options to match.
     * @param string $message The failure message.
     */
    public function assertJobQueued(string $className, array|null $arguments = null, array $options = [], string $message = ''): void
    {
        $this->assertThat(
            $this->getQueuedMessages(),
            new JobQueued($className, $arguments, $options),
            $message
        );
    }

    /**
     * Assert no jobs were dispatched.
     *
     * @param string $message The failure message.
     */
    public function assertNoJobsQueued(string $message = ''): void
    {
        $this->assertEmpty($this->getQueuedMessages(), $message);
    }

    /**
     * Returns dispatched jobs.
     *
     * @return Message[] The captured messages.
     */
    public function getQueuedMessages(): array
    {
        return TestQueue::getMessages();
    }

    /**
     * Replaces configured queues with capturing handlers.
     */
    #[Before(-1)]
    protected function setupQueueHandlers(): void
    {
        $this->queueManager = $this->app->use(QueueManager::class);
        $this->queueConfigs = $this->queueManager->getConfig() ?? [];

        $this->queueManager->clear();

        TestQueue::clearMessages();

        $configs = $this->queueConfigs ?: [QueueManager::DEFAULT => []];

        foreach ($configs as $key => $config) {
            $config['className'] = TestQueue::class;

            $this->queueManager->setConfig($key, $config);
        }
    }

    /**
     * Restores queue configuration and clears captured jobs.
     */
    #[After]
    protected function tearDownQueueHandlers(): void
    {
        if (!isset($this->queueManager)) {
            return;
        }

        TestQueue::clearMessages();

        $this->queueManager->clear();

        foreach ($this->queueConfigs as $key => $config) {
            $this->queueManager->setConfig($key, $config);
        }
    }
}
