<?php
declare(strict_types=1);

namespace Fyre\Commands;

use Fyre\Console\Command;
use Fyre\Console\Console;
use Fyre\DB\ConnectionManager;
use Fyre\DB\Expressions\FunctionExpression;
use Fyre\DB\Forge\Presets\LocksPreset;
use Fyre\DB\Query;
use Override;

use function sprintf;

/**
 * Implements the db lock purge console command.
 *
 * Removes expired database locks.
 */
class DbLockPurgeCommand extends Command
{
    #[Override]
    protected readonly string|null $alias = 'db:lock:purge';

    #[Override]
    protected readonly string $description = 'Remove expired database locks.';

    #[Override]
    protected readonly array $options = [
        'db' => [
            'help' => 'Database connection to use.',
            'default' => ConnectionManager::DEFAULT,
        ],
    ];

    /**
     * {@inheritDoc}
     *
     * @param Console $io The Console.
     * @param ConnectionManager $connectionManager The ConnectionManager.
     * @param LocksPreset $locksPreset The LocksPreset.
     */
    public function __construct(
        Console $io,
        protected ConnectionManager $connectionManager,
        protected LocksPreset $locksPreset,
    ) {
        parent::__construct($io);
    }

    /**
     * Runs the command.
     *
     * @param string $db The connection key.
     * @return int|null The exit code.
     */
    public function run(string $db): int|null
    {
        $connection = $this->connectionManager->use($db);

        if (!$this->locksPreset->exists($connection)) {
            $this->io->info('Database lock storage is not initialized.');

            return static::CODE_SUCCESS;
        }

        $connection
            ->delete()
            ->from(LocksPreset::TABLE)
            ->where([
                'expires <=' => static fn(Query $query): FunctionExpression => $query->func()->now(),
            ])
            ->execute();

        $count = $connection->affectedRows() ?? 0;

        if ($count === 0) {
            $this->io->info('No expired database locks found.');
        } else {
            sprintf(
                'Purged %d expired database lock(s).',
                $count
            ) |> $this->io->success(...);
        }

        return static::CODE_SUCCESS;
    }
}
