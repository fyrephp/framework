<?php
declare(strict_types=1);

namespace Fyre\Commands;

use Fyre\Console\Command;
use Fyre\Console\Console;
use Fyre\DB\ConnectionManager;
use Fyre\DB\Migration\MigrationRunner;
use Override;

use function sprintf;

/**
 * Implements the db rollback console command.
 *
 * Rolls back previously applied migrations.
 */
class DbRollbackCommand extends Command
{
    #[Override]
    protected readonly string|null $alias = 'db:rollback';

    #[Override]
    protected readonly string $description = 'Perform database rollbacks.';

    #[Override]
    protected readonly array $options = [
        'db' => [
            'help' => 'Database connection to use.',
            'default' => ConnectionManager::DEFAULT,
        ],
        'batches' => [
            'help' => 'Number of migration batches to roll back.',
            'as' => 'integer',
            'default' => 1,
        ],
        'steps' => [
            'help' => 'Number of migrations to roll back.',
            'as' => 'integer',
            'default' => null,
        ],
        'lockExpires' => [
            'help' => 'Migration lock lifetime in seconds.',
            'as' => 'integer',
            'default' => 300,
        ],
        'dryRun' => [
            'help' => 'Display the rollback plan without executing it.',
            'as' => 'boolean',
            'default' => false,
        ],
    ];

    /**
     * {@inheritDoc}
     *
     * @param Console $io The Console.
     * @param ConnectionManager $connectionManager The ConnectionManager.
     * @param MigrationRunner $migrationRunner The MigrationRunner.
     */
    public function __construct(
        Console $io,
        protected ConnectionManager $connectionManager,
        protected MigrationRunner $migrationRunner,
    ) {
        parent::__construct($io);
    }

    /**
     * Runs the command.
     *
     * @param string $db The connection key.
     * @param int|null $batches The number of batches to rollback.
     * @param int|null $steps The number of steps to rollback.
     * @param int $lockExpires The migration lock lifetime in seconds.
     * @param bool $dryRun Whether to display the rollback plan without executing it.
     * @return int|null The exit code.
     */
    public function run(
        string $db,
        int|null $batches = 1,
        int|null $steps = null,
        int $lockExpires = 300,
        bool $dryRun = false
    ): int|null {
        $connection = $this->connectionManager->use($db);

        $migrationRunner = $this->migrationRunner->setConnection($connection);

        if (!$dryRun) {
            $migrationRunner
                ->setLockExpires($lockExpires)
                ->rollback($batches, $steps);

            $count = $migrationRunner->getLastMigrationCount();

            if ($count === 0) {
                $this->io->info('No migrations to roll back.');
            } else {
                sprintf(
                    'Rolled back %d migration(s).',
                    $count
                ) |> $this->io->success(...);
            }

            return static::CODE_SUCCESS;
        }

        $migrations = $migrationRunner->getRollbackMigrations($batches, $steps);

        if ($migrations === []) {
            $this->io->info('No migrations to roll back.');

            return static::CODE_SUCCESS;
        }

        $data = [];
        foreach ($migrations as $migrationName => $_) {
            $data[] = [$migrationName, 'down'];
        }

        $this->io->table($data, ['Migration', 'Action']);

        return static::CODE_SUCCESS;
    }
}
