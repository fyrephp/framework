<?php
declare(strict_types=1);

namespace Fyre\Commands;

use Fyre\Commands\Traits\QueueFailureTrait;
use Fyre\Console\Command;
use Fyre\Console\Console;
use Fyre\Queue\Queue;
use Fyre\Queue\QueueManager;
use Override;

use function array_keys;
use function count;
use function sprintf;

/**
 * Implements the queue retry console command.
 */
class QueueRetryCommand extends Command
{
    use QueueFailureTrait;

    #[Override]
    protected readonly string|null $alias = 'queue:retry';

    #[Override]
    protected readonly string $description = 'Retry failed queue jobs.';

    #[Override]
    protected readonly array $options = [
        'ids' => [
            'help' => 'Comma-separated failed job IDs.',
        ],
        'config' => [
            'help' => 'Queue configuration to use.',
            'default' => QueueManager::DEFAULT,
        ],
        'queue' => [
            'help' => 'Queue name to use.',
            'default' => Queue::DEFAULT,
        ],
        'class' => [
            'help' => 'Filter jobs by class name.',
        ],
        'force' => [
            'help' => 'Skip the confirmation prompt.',
            'as' => 'boolean',
            'default' => false,
        ],
    ];

    /**
     * {@inheritDoc}
     *
     * @param Console $io The Console.
     * @param QueueManager $queueManager The QueueManager.
     */
    public function __construct(
        Console $io,
        protected QueueManager $queueManager,
    ) {
        parent::__construct($io);
    }

    /**
     * Runs the command.
     *
     * @param string $config The queue config key.
     * @param string $queue The queue name.
     * @param string|null $ids The comma-separated failed job identifiers.
     * @param string|null $class The job class name.
     * @param bool $force Whether to skip confirmation.
     * @return int|null The exit code.
     */
    public function run(
        string $config,
        string $queue,
        string|null $ids = null,
        string|null $class = null,
        bool $force = false
    ): int|null {
        $handler = $this->queueManager->use($config);
        $failureIds = static::getFilteredFailures($handler, $queue, $ids, $class)
            |> array_keys(...);

        if ($failureIds === []) {
            $this->io->info('No failed queue jobs matched.');

            return static::CODE_SUCCESS;
        }

        $count = count($failureIds);

        if (!$force && !$this->io->confirm(sprintf(
            'Retry %d failed queue job(s)?',
            $count
        ), false)) {
            $this->io->info('Cancelled.');

            return static::CODE_SUCCESS;
        }

        $result = static::CODE_SUCCESS;
        $retried = 0;

        foreach ($failureIds as $id) {
            if ($handler->retryFailed($id, $queue)) {
                $retried++;

                continue;
            }

            sprintf(
                'Failed queue job could not be retried: %s',
                $id
            ) |> $this->io->error(...);
            $result = static::CODE_ERROR;
        }

        if ($retried > 0) {
            sprintf(
                'Retried %d failed queue job(s).',
                $retried
            ) |> $this->io->success(...);
        }

        return $result;
    }
}
