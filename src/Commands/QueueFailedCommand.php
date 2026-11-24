<?php
declare(strict_types=1);

namespace Fyre\Commands;

use Fyre\Commands\Traits\QueueFailureTrait;
use Fyre\Console\Command;
use Fyre\Console\Console;
use Fyre\Queue\Queue;
use Fyre\Queue\QueueManager;
use Override;

use function gmdate;
use function strnatcmp;
use function uksort;

/**
 * Implements the queue failed console command.
 *
 * Displays retained failures for a queue.
 */
class QueueFailedCommand extends Command
{
    use QueueFailureTrait;

    #[Override]
    protected readonly string|null $alias = 'queue:failed';

    #[Override]
    protected readonly string $description = 'Display failed queue jobs.';

    #[Override]
    protected readonly array $options = [
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
     * @param string|null $class The job class name.
     * @return int|null The exit code.
     */
    public function run(string $config, string $queue, string|null $class = null): int|null
    {
        $handler = $this->queueManager->use($config);
        $failures = static::getFilteredFailures($handler, $queue, class: $class);

        if ($failures === []) {
            $this->io->info('No failed queue jobs found.');

            return static::CODE_SUCCESS;
        }

        uksort(
            $failures,
            static function(string $a, string $b) use ($failures): int {
                $order = $failures[$b]->getFailedAt() <=> $failures[$a]->getFailedAt();

                if ($order !== 0) {
                    return $order;
                }

                return strnatcmp($a, $b);
            }
        );

        $data = [];
        foreach ($failures as $id => $failure) {
            $message = $failure->getMessage()->getConfig();
            $exceptionClass = $failure->getExceptionClass();

            $data[] = [
                $id,
                $message['className'].'::'.$message['method'],
                gmdate('Y-m-d\TH:i:s\Z', $failure->getFailedAt()),
                $exceptionClass === null ? '-' : $exceptionClass.': '.$failure->getExceptionMessage(),
            ];
        }

        $this->io->table($data, ['ID', 'Job', 'Failed', 'Exception']);

        return static::CODE_SUCCESS;
    }
}
