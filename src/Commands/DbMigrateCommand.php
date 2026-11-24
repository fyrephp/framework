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
 * Implements the db migrate console command.
 *
 * Runs all pending migrations using the configured migration namespaces.
 */
class DbMigrateCommand extends Command
{
    #[Override]
    protected readonly string|null $alias = 'db:migrate';

    #[Override]
    protected readonly string $description = 'Perform database migrations.';

    #[Override]
    protected readonly array $options = [
        'db' => [
            'help' => 'Database connection to use.',
            'default' => ConnectionManager::DEFAULT,
        ],
        'lockExpires' => [
            'help' => 'Migration lock lifetime in seconds.',
            'as' => 'integer',
            'default' => 300,
        ],
        'dryRun' => [
            'help' => 'Display the migration plan without executing it.',
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
     * @param int $lockExpires The migration lock lifetime in seconds.
     * @param bool $dryRun Whether to display the migration plan without executing it.
     * @return int|null The exit code.
     */
    public function run(string $db, int $lockExpires = 300, bool $dryRun = false): int|null
    {
        $connection = $this->connectionManager->use($db);

        $migrationRunner = $this->migrationRunner->setConnection($connection);

        if (!$dryRun) {
            $migrationRunner
                ->setLockExpires($lockExpires)
                ->migrate();

            $count = $migrationRunner->getLastMigrationCount();

            if ($count === 0) {
                $this->io->info('No pending migrations.');
            } else {
                sprintf(
                    'Applied %d migration(s).',
                    $count
                ) |> $this->io->success(...);
            }

            return static::CODE_SUCCESS;
        }

        $migrations = $migrationRunner->getPendingMigrations();

        if ($migrations === []) {
            $this->io->info('No pending migrations.');

            return static::CODE_SUCCESS;
        }

        $data = [];
        foreach ($migrations as $migrationName => $_) {
            $data[] = [$migrationName, 'up'];
        }

        $this->io->table($data, ['Migration', 'Action']);

        return static::CODE_SUCCESS;
    }
}
