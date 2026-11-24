<?php
declare(strict_types=1);

namespace Fyre\Commands\Traits;

use Fyre\Console\Command;
use Fyre\Queue\FailedMessage;
use Fyre\Queue\Queue;

use function array_fill_keys;
use function array_filter;
use function array_intersect_key;
use function array_map;
use function explode;
use function trim;

/**
 * Provides failed queue job filtering.
 *
 * @internal
 *
 * @phpstan-require-extends Command
 */
trait QueueFailureTrait
{
    /**
     * Returns filtered failed jobs.
     *
     * @param Queue $handler The Queue handler.
     * @param string $queue The queue name.
     * @param string|null $ids The comma-separated failed job identifiers.
     * @param string|null $class The job class name.
     * @return array<string, FailedMessage> The failed jobs indexed by identifier.
     */
    protected static function getFilteredFailures(
        Queue $handler,
        string $queue,
        string|null $ids = null,
        string|null $class = null
    ): array {
        $failures = $handler->getFailed($queue);

        if ($ids !== null) {
            $ids = explode(',', $ids)
                |> array_map(trim(...), ?)
                |> array_filter(?, static fn(string $id): bool => $id !== '');

            $failures = array_intersect_key($failures, array_fill_keys($ids, true));
        }

        if ($class !== null) {
            $failures = array_filter(
                $failures,
                static fn(FailedMessage $failure): bool => $failure->getMessage()->getConfig()['className'] === $class
            );
        }

        return $failures;
    }
}
