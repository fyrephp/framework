<?php
declare(strict_types=1);

namespace Fyre\Commands;

use Fyre\Console\Command;
use Fyre\Console\Console;
use Fyre\DB\ConnectionManager;
use Fyre\DB\Migration\MigrationRunner;
use Override;

use function array_map;

/**
 * Implements the db status console command.
 *
 * Displays the status of discovered and recorded migrations.
 */
class DbMigrationStatusCommand extends Command
{
    #[Override]
    protected readonly string|null $alias = 'db:status';

    #[Override]
    protected readonly string $description = 'Display database migration status.';

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
     * @return int|null The exit code.
     */
    public function run(string $db): int|null
    {
        $connection = $this->connectionManager->use($db);

        $status = $this->migrationRunner
            ->setConnection($connection)
            ->getStatus();

        if ($status === []) {
            $this->io->info('No migrations found.');

            return static::CODE_SUCCESS;
        }

        $data = array_map(
            static fn(array $data): array => [
                $data['migration'],
                $data['status'],
                $data['batch'] ?? '-',
            ],
            $status
        );

        $this->io->table($data, ['Migration', 'Status', 'Batch']);

        return static::CODE_SUCCESS;
    }
}
