<?php
declare(strict_types=1);

namespace Fyre\Commands;

use Fyre\Console\Command;
use Fyre\Console\Console;
use Fyre\Core\Container;
use Fyre\Queue\Queue;
use Fyre\Queue\QueueManager;
use Fyre\Queue\Worker;
use Override;

use function sprintf;

/**
 * Implements the queue worker console command.
 */
class QueueWorkerCommand extends Command
{
    #[Override]
    protected readonly string|null $alias = 'queue:worker';

    #[Override]
    protected readonly string $description = 'Start a queue worker.';

    #[Override]
    protected readonly array $options = [
        'config' => [
            'help' => 'Queue configuration to use.',
            'default' => QueueManager::DEFAULT,
        ],
        'queue' => [
            'help' => 'Queue name to process.',
            'default' => Queue::DEFAULT,
        ],
        'maxJobs' => [
            'help' => 'Maximum jobs to process before stopping.',
            'as' => 'integer',
            'default' => 0,
        ],
        'maxRuntime' => [
            'help' => 'Maximum seconds to run before stopping.',
            'as' => 'integer',
            'default' => 0,
        ],
    ];

    /**
     * {@inheritDoc}
     *
     * @param Console $io The Console.
     * @param Container $container The Container.
     */
    public function __construct(
        Console $io,
        protected Container $container,
    ) {
        parent::__construct($io);
    }

    /**
     * Runs the command.
     *
     * @param string $config The queue config key.
     * @param string $queue The queue name.
     * @param int $maxJobs The maximum number of jobs to run.
     * @param int $maxRuntime The maximum number of seconds to run.
     * @return int|null The exit code.
     */
    public function run(string $config, string $queue, int $maxJobs, int $maxRuntime): int|null
    {
        $worker = $this->container->use(Worker::class, [
            'options' => [
                'config' => $config,
                'queue' => $queue,
                'maxJobs' => $maxJobs,
                'maxRuntime' => $maxRuntime,
            ],
        ]);

        sprintf(
            'Queue worker started: %s/%s.',
            $config,
            $queue
        ) |> $this->io->info(...);

        $worker->run();

        sprintf(
            'Queue worker stopped: %s/%s.',
            $config,
            $queue
        ) |> $this->io->info(...);

        return static::CODE_SUCCESS;
    }
}
