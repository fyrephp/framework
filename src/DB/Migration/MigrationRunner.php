<?php
declare(strict_types=1);

namespace Fyre\DB\Migration;

use DirectoryIterator;
use Fyre\Core\Container;
use Fyre\Core\Loader;
use Fyre\Core\Traits\DebugTrait;
use Fyre\Core\Traits\NamespacesTrait;
use Fyre\DB\Connection;
use Fyre\DB\ConnectionManager;
use Fyre\DB\Exceptions\DbException;
use Fyre\DB\Forge\Forge;
use Fyre\DB\Forge\ForgeRegistry;
use Fyre\DB\Forge\Presets\LocksPreset;
use Fyre\DB\Forge\Presets\MigrationsPreset;
use Fyre\DB\Lock;
use InvalidArgumentException;
use ReflectionClass;
use RegexIterator;

use function array_column;
use function array_diff_key;
use function array_flip;
use function array_replace;
use function is_subclass_of;
use function ksort;
use function method_exists;
use function sprintf;
use function substr;

use const SORT_NATURAL;

/**
 * Runs migrations and updates migration history.
 *
 * Applies migrations in order and records execution to {@see MigrationHistory}.
 */
class MigrationRunner
{
    use DebugTrait;
    use NamespacesTrait;

    protected Connection|null $connection = null;

    protected MigrationHistory|null $history = null;

    protected int $lastMigrationCount = 0;

    protected int $lockExpires = 300;

    /**
     * @var array<string, class-string<Migration>>|null
     */
    protected array|null $migrations = null;

    /**
     * Constructs a MigrationRunner.
     *
     * @param Container $container The Container.
     * @param Loader $loader The Loader.
     * @param ConnectionManager $connectionManager The ConnectionManager.
     * @param ForgeRegistry $forgeRegistry The ForgeRegistry.
     * @param LocksPreset $locksPreset The LocksPreset.
     */
    public function __construct(
        protected Container $container,
        protected Loader $loader,
        protected ConnectionManager $connectionManager,
        protected ForgeRegistry $forgeRegistry,
        protected LocksPreset $locksPreset
    ) {}

    /**
     * Clears loaded migrations.
     */
    public function clear(): void
    {
        $this->clearNamespaces();
        $this->connection = null;
        $this->history = null;
        $this->migrations = null;
        $this->lastMigrationCount = 0;
    }

    /**
     * Returns the Connection.
     *
     * @return Connection The Connection instance.
     */
    public function getConnection(): Connection
    {
        return $this->connection ??= $this->connectionManager->use();
    }

    /**
     * Returns the Forge.
     *
     * @return Forge The Forge instance.
     */
    public function getForge(): Forge
    {
        return $this->getConnection() |> $this->forgeRegistry->use(...);
    }

    /**
     * Returns the MigrationHistory.
     *
     * @return MigrationHistory The MigrationHistory instance.
     */
    public function getHistory(): MigrationHistory
    {
        return $this->history ??= $this->container->build(MigrationHistory::class, [
            'connection' => $this->getConnection(),
        ]);
    }

    /**
     * Returns the number of migrations executed by the last migrate or rollback operation.
     *
     * @return int The migration count.
     */
    public function getLastMigrationCount(): int
    {
        return $this->lastMigrationCount;
    }

    /**
     * Returns all migrations.
     *
     * @return array<string, class-string<Migration>> The migrations.
     */
    public function getMigrations(): array
    {
        return $this->migrations ??= $this->findMigrations();
    }

    /**
     * Returns pending migrations.
     *
     * @return array<string, class-string<Migration>> The pending migrations.
     */
    public function getPendingMigrations(): array
    {
        return $this->getHistory()->all()
            |> array_column(?, 'migration')
            |> array_flip(...)
            |> array_diff_key($this->getMigrations(), ?);
    }

    /**
     * Returns migrations to rollback.
     *
     * @param int|null $batches The number of batches to rollback.
     * @param int|null $steps The number of steps to rollback.
     * @return array<string, class-string<Migration>> The migrations to rollback.
     *
     * @throws DbException If a recorded migration implementation cannot be found.
     */
    public function getRollbackMigrations(int|null $batches = 1, int|null $steps = null): array
    {
        $migrations = $this->getMigrations();
        $rollbackMigrations = [];

        $ranMigrations = $this->getHistory()->all();
        $lastBatch = null;

        foreach ($ranMigrations as $data) {
            if ($batches !== null && $data['batch'] !== $lastBatch && $batches-- <= 0) {
                break;
            }

            if ($steps !== null && $steps-- <= 0) {
                break;
            }

            $migrationName = $data['migration'];
            $lastBatch = $data['batch'];

            if (!isset($migrations[$migrationName])) {
                throw new DbException(sprintf(
                    'Migration implementation `%s` could not be found.',
                    $migrationName
                ));
            }

            $rollbackMigrations[$migrationName] = $migrations[$migrationName];
        }

        return $rollbackMigrations;
    }

    /**
     * Returns the migration status.
     *
     * @return array<array{migration: string, status: 'down'|'missing'|'up', batch: int|null}> The migration status.
     */
    public function getStatus(): array
    {
        $migrations = $this->getMigrations();
        $ranMigrationsByName = $this->getHistory()->all() |> array_column(?, null, 'migration');

        $allMigrations = array_replace($migrations, $ranMigrationsByName);

        ksort($allMigrations, SORT_NATURAL);

        $status = [];

        foreach ($allMigrations as $migrationName => $_) {
            $status[] = [
                'migration' => $migrationName,
                'status' => match (true) {
                    !isset($ranMigrationsByName[$migrationName]) => 'down',
                    isset($migrations[$migrationName]) => 'up',
                    default => 'missing',
                },
                'batch' => $ranMigrationsByName[$migrationName]['batch'] ?? null,
            ];
        }

        return $status;
    }

    /**
     * Migrates to the latest version.
     *
     * Executes migrations selected by {@see MigrationRunner::getPendingMigrations()}.
     * Migrations are instantiated with the current {@see Forge} and `up()` is called when
     * present.
     *
     * Note: Migrations are not automatically wrapped in a transaction.
     *
     * @return static The MigrationRunner instance.
     *
     * @throws DbException If the migration lock cannot be acquired or is lost.
     */
    public function migrate(): static
    {
        $this->lastMigrationCount = 0;

        $history = $this->getHistory();
        $history->checkTable();

        $this->locksPreset->check($this->getConnection());

        $lock = $this->buildLock();

        if (!$lock->acquire()) {
            throw new DbException('Migration lock could not be acquired.');
        }

        try {
            $batch = $history->getNextBatch();

            $pendingMigrations = $this->getPendingMigrations();

            if ($pendingMigrations !== [] && !$lock->refresh()) {
                throw new DbException('Migration lock was lost.');
            }

            foreach ($pendingMigrations as $migrationName => $className) {
                $migration = $this->container->build($className, ['forge' => $this->getForge()]);

                if (method_exists($migration, 'up')) {
                    $this->container->call([$migration, 'up']);
                }

                $history->add($migrationName, $batch);

                $this->lastMigrationCount++;

                if (!$lock->refresh()) {
                    throw new DbException('Migration lock was lost.');
                }
            }
        } finally {
            $lock->release();
        }

        return $this;
    }

    /**
     * Rolls back to a previous version.
     *
     * Executes migrations selected by {@see MigrationRunner::getRollbackMigrations()} in
     * reverse history order (latest first). For each migration, `down()` is called when
     * present, and the migration is removed from history.
     *
     * @param int|null $batches The number of batches to rollback.
     * @param int|null $steps The number of steps to rollback.
     * @return static The MigrationRunner instance.
     *
     * @throws DbException If a recorded migration implementation cannot be found, or the
     *                     migration lock cannot be acquired or is lost.
     */
    public function rollback(int|null $batches = 1, int|null $steps = null): static
    {
        $this->lastMigrationCount = 0;

        $history = $this->getHistory();
        $history->checkTable();

        $this->locksPreset->check($this->getConnection());

        $lock = $this->buildLock();

        if (!$lock->acquire()) {
            throw new DbException('Migration lock could not be acquired.');
        }

        try {
            $rollbackMigrations = $this->getRollbackMigrations($batches, $steps);

            if ($rollbackMigrations !== [] && !$lock->refresh()) {
                throw new DbException('Migration lock was lost.');
            }

            foreach ($rollbackMigrations as $migrationName => $className) {
                $migration = $this->container->build($className, ['forge' => $this->getForge()]);

                if (method_exists($migration, 'down')) {
                    $this->container->call([$migration, 'down']);
                }

                $history->delete($migrationName);

                $this->lastMigrationCount++;

                if (!$lock->refresh()) {
                    throw new DbException('Migration lock was lost.');
                }
            }
        } finally {
            $lock->release();
        }

        return $this;
    }

    /**
     * Sets the Connection.
     *
     * @param Connection $connection The Connection.
     * @return static The MigrationRunner instance.
     */
    public function setConnection(Connection $connection): static
    {
        $this->connection = $connection;
        $this->history = null;
        $this->migrations = null;

        return $this;
    }

    /**
     * Sets the migration lock lifetime.
     *
     * @param int $lockExpires The migration lock lifetime in seconds.
     * @return static The MigrationRunner instance.
     *
     * @throws InvalidArgumentException If the expiration is not valid.
     */
    public function setLockExpires(int $lockExpires): static
    {
        if ($lockExpires < 1) {
            throw new InvalidArgumentException('Migration lock expiration must be greater than 0.');
        }

        $this->lockExpires = $lockExpires;

        return $this;
    }

    /**
     * Builds the migration lock.
     *
     * @return Lock The Lock instance.
     */
    protected function buildLock(): Lock
    {
        return $this->getConnection()
            ->lock(MigrationsPreset::TABLE, $this->lockExpires);
    }

    /**
     * Finds the migration classes.
     *
     * Migrations are discovered by scanning the configured namespaces for files matching
     * `Migration_*.php` and filtering to non-abstract subclasses of {@see Migration}.
     * The migration name is derived from the class short name after the `Migration_` prefix.
     *
     * @return array<string, class-string<Migration>> The migration classes.
     */
    protected function findMigrations(): array
    {
        $migrations = [];
        foreach ($this->namespaces as $namespace) {
            $folders = $this->loader->findFolders($namespace);

            foreach ($folders as $folder) {
                $directory = new DirectoryIterator($folder);
                $iterator = new RegexIterator($directory, '/\AMigration_\w+.*\.php\z/');

                foreach ($iterator as $item) {
                    if ($item->isDir()) {
                        continue;
                    }

                    $name = $item->getBasename('.php');

                    $className = $namespace.$name;

                    if (!is_subclass_of($className, Migration::class)) {
                        continue;
                    }

                    $reflection = new ReflectionClass($className);

                    if ($reflection->isAbstract()) {
                        continue;
                    }

                    $name = $reflection->getShortName() |> substr(?, 10);

                    $migrations[$name] = $className;
                }
            }
        }

        ksort($migrations, SORT_NATURAL);

        return $migrations;
    }
}
