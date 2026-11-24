<?php
declare(strict_types=1);

namespace Fyre\Commands;

use Fyre\Console\Command;
use Fyre\Console\Console;
use Fyre\Core\Make;
use Fyre\Core\Make\GeneratedFile;
use Fyre\DB\Migration\MigrationRunner;
use Fyre\Utility\Path;
use Override;

use function date;
use function sprintf;

/**
 * Implements the make migration console command.
 *
 * Generates a migration class using the `migration` stub.
 */
class MakeMigrationCommand extends Command
{
    #[Override]
    protected readonly string|null $alias = 'make:migration';

    #[Override]
    protected readonly string $description = 'Generate a new migration.';

    #[Override]
    protected readonly array $options = [
        'name' => [
            'help' => 'Name of the migration to generate.',
            'required' => true,
        ],
        'version' => [
            'help' => 'Version for the generated migration.',
        ],
        'namespace' => [
            'help' => 'Namespace for the generated migration.',
        ],
        'force' => [
            'help' => 'Overwrite an existing file.',
            'as' => 'boolean',
            'default' => false,
        ],
    ];

    /**
     * {@inheritDoc}
     *
     * @param Console $io The Console.
     * @param Make $make The Make.
     * @param MigrationRunner $migrationRunner The MigrationRunner.
     */
    public function __construct(
        Console $io,
        protected Make $make,
        protected MigrationRunner $migrationRunner,
    ) {
        parent::__construct($io);
    }

    /**
     * Runs the command.
     *
     * Note: When no version is supplied, the version is generated as `YmdHis`. The namespace defaults to the
     * first registered {@see MigrationRunner} namespace, or `App\Migrations`.
     *
     * @param string $name The migration name.
     * @param string|null $version The migration version.
     * @param string|null $namespace The migration namespace.
     * @param bool $force Whether to overwrite an existing file.
     * @return int|null The exit code.
     */
    public function run(string $name, string|null $version = null, string|null $namespace = null, bool $force = false): int|null
    {
        $version ??= date('YmdHis');
        $namespace ??= $this->migrationRunner->getNamespaces()[0] ?? 'App\Migrations';

        $migration = 'Migration_'.$version.'_'.$name;

        [$namespace, $className] = Make::parseNamespaceClass($namespace, $migration);

        $contents = Make::loadStub('migration', [
            '{namespace}' => $namespace,
            '{class}' => $className,
        ]);

        $path = $this->make->findPath($namespace);

        if (!$path) {
            $this->io->error('Namespace path not found.');

            return static::CODE_ERROR;
        }

        $generatedFile = new GeneratedFile(
            Path::join($path, $className.'.php'),
            $contents
        );

        if (!$generatedFile->isValid($force)) {
            $this->io->error('Migration file already exists.');

            return static::CODE_ERROR;
        }

        if (!$generatedFile->save()) {
            $this->io->error('Migration file could not be written.');

            return static::CODE_ERROR;
        }

        sprintf(
            'Generated: %s',
            $generatedFile->getPath()
        ) |> $this->io->success(...);

        return static::CODE_SUCCESS;
    }
}
