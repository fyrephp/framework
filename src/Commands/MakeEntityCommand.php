<?php
declare(strict_types=1);

namespace Fyre\Commands;

use Fyre\Console\Command;
use Fyre\Console\Console;
use Fyre\Core\Make;
use Fyre\Core\Make\EntitySourceBuilder;
use Fyre\Core\Make\GeneratedFile;
use Fyre\DB\ConnectionManager;
use Fyre\DB\Schema\SchemaRegistry;
use Fyre\ORM\EntityLocator;
use Fyre\ORM\Model;
use Fyre\ORM\ModelRegistry;
use Fyre\Utility\Inflector;
use Fyre\Utility\Path;
use InvalidArgumentException;
use Override;

use function sprintf;

/**
 * Implements the make entity console command.
 *
 * Generates an entity class using the `entity` stub.
 */
class MakeEntityCommand extends Command
{
    #[Override]
    protected readonly string|null $alias = 'make:entity';

    #[Override]
    protected readonly string $description = 'Generate a new entity.';

    #[Override]
    protected readonly array $options = [
        'name' => [
            'help' => 'Name of the entity to generate.',
            'text' => 'Please enter a name for the entity',
            'required' => true,
        ],
        'namespace' => [
            'help' => 'Namespace for the generated entity.',
        ],
        'table' => [
            'help' => 'Database table used for schema inference.',
        ],
        'connection' => [
            'help' => 'Database connection used for schema inference.',
            'default' => ConnectionManager::DEFAULT,
        ],
        'noFields' => [
            'help' => 'Skip schema field annotations.',
            'as' => 'boolean',
            'default' => false,
        ],
        'noRelationships' => [
            'help' => 'Skip relationship annotations.',
            'as' => 'boolean',
            'default' => false,
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
     * @param EntityLocator $entityLocator The EntityLocator.
     * @param ModelRegistry $modelRegistry The ModelRegistry.
     * @param ConnectionManager $connectionManager The ConnectionManager.
     * @param SchemaRegistry $schemaRegistry The SchemaRegistry.
     * @param Inflector $inflector The Inflector.
     * @param EntitySourceBuilder $sourceBuilder The entity source builder.
     */
    public function __construct(
        Console $io,
        protected Make $make,
        protected EntityLocator $entityLocator,
        protected ModelRegistry $modelRegistry,
        protected ConnectionManager $connectionManager,
        protected SchemaRegistry $schemaRegistry,
        protected Inflector $inflector,
        protected EntitySourceBuilder $sourceBuilder,
    ) {
        parent::__construct($io);
    }

    /**
     * Runs the command.
     *
     * Note: The namespace defaults to the first registered {@see EntityLocator} namespace, or `App\Entities`.
     * The table defaults to the tableized entity class name.
     *
     * @param string $name The entity name.
     * @param string|null $namespace The entity namespace.
     * @param string|null $table The database table.
     * @param string $connection The database connection.
     * @param bool $noFields Whether to skip schema field annotations.
     * @param bool $noRelationships Whether to skip schema relationship annotations.
     * @param bool $force Whether to overwrite an existing file.
     * @return int|null The exit code.
     */
    public function run(
        string $name,
        string|null $namespace = null,
        string|null $table = null,
        string $connection = ConnectionManager::DEFAULT,
        bool $noFields = false,
        bool $noRelationships = false,
        bool $force = false
    ): int|null {
        $namespace ??= $this->entityLocator->getNamespaces()[0] ?? 'App\Entities';

        [$namespace, $className] = Make::parseNamespaceClass($namespace, $name);

        $tableName = $table ?? $this->inflector->tableize($className);
        $model = $this->modelRegistry->build($className);
        $fields = [];

        if (!$this->connectionManager->hasConfig($connection)) {
            if ($table !== null) {
                $this->io->error('Database connection config not found.');

                return static::CODE_ERROR;
            }
        } else {
            $this->connectionManager->use($connection) |> $model->setConnection(...);

            if ($table !== null || $model::class === Model::class) {
                $model->setTable($tableName);
            }
        }

        if (!$noFields) {
            try {
                $schema = $model->getConnection() |> $this->schemaRegistry->use(...);
            } catch (InvalidArgumentException) {
                if ($table !== null) {
                    $this->io->error('Database schema handler not found.');

                    return static::CODE_ERROR;
                }

                $schema = null;
            }

            $tableName = $model->getTable();

            if ($schema && $schema->hasTable($tableName)) {
                $fields = $schema->table($tableName)
                    ->columns()
                    ->values()
                    ->toArray();
            } else if ($schema && $table !== null) {
                $this->io->error('Database table not found.');

                return static::CODE_ERROR;
            }
        }

        $relationships = !$noRelationships ?
            $this->sourceBuilder->buildRelationshipData($model->getRelationships(), $namespace) :
            [];
        $contents = $this->sourceBuilder->build($namespace, $className, $fields, $relationships);
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
            $this->io->error('Entity file already exists.');

            return static::CODE_ERROR;
        }

        if (!$generatedFile->save()) {
            $this->io->error('Entity file could not be written.');

            return static::CODE_ERROR;
        }

        sprintf(
            'Generated: %s',
            $generatedFile->getPath()
        ) |> $this->io->success(...);

        return static::CODE_SUCCESS;
    }
}
