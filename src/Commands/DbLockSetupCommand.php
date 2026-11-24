<?php
declare(strict_types=1);

namespace Fyre\Commands;

use Fyre\Console\Command;
use Fyre\Console\Console;
use Fyre\DB\ConnectionManager;
use Fyre\DB\Forge\Presets\LocksPreset;
use Override;

/**
 * Implements the db lock setup console command.
 *
 * Initializes database lock storage.
 */
class DbLockSetupCommand extends Command
{
    #[Override]
    protected readonly string|null $alias = 'db:lock:setup';

    #[Override]
    protected readonly string $description = 'Initialize database lock storage.';

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
        $exists = $this->locksPreset->exists($connection);

        $this->locksPreset->check($connection);

        if ($exists) {
            $this->io->info('Database lock storage is already initialized.');
        } else {
            $this->io->success('Database lock storage initialized.');
        }

        return static::CODE_SUCCESS;
    }
}
