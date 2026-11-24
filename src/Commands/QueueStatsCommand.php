<?php
declare(strict_types=1);

namespace Fyre\Commands;

use Fyre\Console\Command;
use Fyre\Console\Console;
use Fyre\Queue\QueueManager;
use Override;

use function array_filter;
use function array_keys;
use function array_map;

use const ARRAY_FILTER_USE_KEY;

/**
 * Implements the queue stats console command.
 *
 * Displays per-queue stats for the configured queue handlers.
 */
class QueueStatsCommand extends Command
{
    #[Override]
    protected readonly string|null $alias = 'queue:stats';

    #[Override]
    protected readonly string $description = 'Display stats for the queue.';

    #[Override]
    protected readonly array $options = [
        'config' => [
            'help' => 'Queue configuration to display.',
        ],
        'queue' => [
            'help' => 'Queue name to display.',
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
     * @param string|null $config The queue config key.
     * @param string|null $queue The queue name.
     * @return int|null The exit code.
     */
    public function run(string|null $config = null, string|null $queue = null): int|null
    {
        $handlers = $this->queueManager->getConfig() ?? [];

        if ($config !== null) {
            $handlers = array_filter(
                $handlers,
                static fn(string $key): bool => $key === $config,
                ARRAY_FILTER_USE_KEY
            );
        }

        $found = false;

        foreach ($handlers as $key => $_) {
            $instance = $this->queueManager->use($key);
            $activeQueues = $instance->queues();

            if ($queue !== null) {
                $activeQueues = array_filter(
                    $activeQueues,
                    static fn(string $activeQueue): bool => $activeQueue === $queue
                );
            }

            if ($activeQueues === []) {
                continue;
            }

            $this->io->write($key, Console::GREEN, style: Console::BOLD);
            $found = true;

            foreach ($activeQueues as $activeQueue) {
                $stats = $instance->stats($activeQueue);
                $data = array_map(
                    static fn(string $key, mixed $value): array => [$key, $value],
                    array_keys($stats),
                    $stats
                );

                $this->io->write($activeQueue, Console::BLUE);
                $this->io->table($data);
            }
        }

        if (!$found) {
            $this->io->info('No queues found.');
        }

        return static::CODE_SUCCESS;
    }
}
