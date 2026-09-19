<?php
declare(strict_types=1);

namespace Fyre\Commands;

use Fyre\Console\Command;
use Fyre\Console\Console;
use Fyre\Core\Make;
use Fyre\Core\Make\EntitySourceBuilder;
use Fyre\Core\Make\EnumSourceBuilder;
use Fyre\Core\Make\FixtureSourceBuilder;
use Fyre\Core\Make\GeneratedFile;
use Fyre\Core\Make\GenerationBatch;
use Fyre\Core\Make\ModelSourceBuilder;
use Fyre\Core\Make\TestSourceBuilder;
use Fyre\DB\ConnectionManager;
use Fyre\DB\Schema\Column;
use Fyre\DB\Schema\Index;
use Fyre\DB\Schema\SchemaRegistry;
use Fyre\ORM\EntityLocator;
use Fyre\ORM\ModelRegistry;
use Fyre\TestSuite\Fixture\FixtureRegistry;
use Fyre\Utility\Inflector;
use Fyre\Utility\Path;
use InvalidArgumentException;
use Override;

use function preg_replace;
use function sprintf;
use function substr;

/**
 * Implements the make model console command.
 *
 * Generates a model class using the `model` stub.
 *
 * @phpstan-import-type EnumData from ModelSourceBuilder
 * @phpstan-import-type RelationshipData from ModelSourceBuilder
 */
class MakeModelCommand extends Command
{
    #[Override]
    protected string|null $alias = 'make:model';

    #[Override]
    protected string $description = 'Generate a new model.';

    #[Override]
    protected array $options = [
        'name' => [
            'help' => 'Name of the model to generate.',
            'text' => 'Please enter a name for the model',
            'required' => true,
        ],
        'namespace' => [
            'help' => 'Namespace for the generated model.',
        ],
        'table' => [
            'help' => 'Database table used for schema inference.',
        ],
        'connection' => [
            'help' => 'Database connection used for schema inference.',
            'default' => ConnectionManager::DEFAULT,
        ],
        'noEntity' => [
            'help' => 'Skip entity generation.',
            'as' => 'boolean',
            'default' => false,
        ],
        'noFields' => [
            'help' => 'Skip schema field inference.',
            'as' => 'boolean',
            'default' => false,
        ],
        'noRelationships' => [
            'help' => 'Skip relationship inference.',
            'as' => 'boolean',
            'default' => false,
        ],
        'noValidation' => [
            'help' => 'Skip validator inference.',
            'as' => 'boolean',
            'default' => false,
        ],
        'noRules' => [
            'help' => 'Skip rule inference.',
            'as' => 'boolean',
            'default' => false,
        ],
        'noTest' => [
            'help' => 'Skip test generation.',
            'as' => 'boolean',
            'default' => false,
        ],
        'noFixture' => [
            'help' => 'Skip fixture generation.',
            'as' => 'boolean',
            'default' => false,
        ],
        'force' => [
            'help' => 'Overwrite existing files.',
            'as' => 'boolean',
            'default' => false,
        ],
    ];

    /**
     * {@inheritDoc}
     *
     * @param Console $io The Console.
     * @param Make $make The Make.
     * @param ModelRegistry $modelRegistry The ModelRegistry.
     * @param EntityLocator $entityLocator The EntityLocator.
     * @param FixtureRegistry $fixtureRegistry The FixtureRegistry.
     * @param ConnectionManager $connectionManager The ConnectionManager.
     * @param SchemaRegistry $schemaRegistry The SchemaRegistry.
     * @param Inflector $inflector The Inflector.
     * @param EntitySourceBuilder $entitySourceBuilder The entity source builder.
     * @param EnumSourceBuilder $enumSourceBuilder The enum source builder.
     * @param FixtureSourceBuilder $fixtureSourceBuilder The fixture source builder.
     * @param ModelSourceBuilder $modelSourceBuilder The model source builder.
     * @param TestSourceBuilder $testSourceBuilder The test source builder.
     */
    public function __construct(
        Console $io,
        protected Make $make,
        protected ModelRegistry $modelRegistry,
        protected EntityLocator $entityLocator,
        protected FixtureRegistry $fixtureRegistry,
        protected ConnectionManager $connectionManager,
        protected SchemaRegistry $schemaRegistry,
        protected Inflector $inflector,
        protected EntitySourceBuilder $entitySourceBuilder,
        protected EnumSourceBuilder $enumSourceBuilder,
        protected FixtureSourceBuilder $fixtureSourceBuilder,
        protected ModelSourceBuilder $modelSourceBuilder,
        protected TestSourceBuilder $testSourceBuilder,
    ) {
        parent::__construct($io);
    }

    /**
     * Runs the command.
     *
     * Note: The namespace defaults to the first registered {@see ModelRegistry} namespace, or `App\Models`.
     * The generated class name is suffixed with `Model`.
     *
     * @param string $name The model name.
     * @param string|null $namespace The model namespace.
     * @param string|null $table The database table.
     * @param string $connection The database connection.
     * @param bool $noEntity Whether to skip entity generation.
     * @param bool $noFields Whether to skip schema field inference.
     * @param bool $noRelationships Whether to skip relationship inference.
     * @param bool $noValidation Whether to skip validator inference.
     * @param bool $noRules Whether to skip RuleSet inference.
     * @param bool $noTest Whether to skip test generation.
     * @param bool $noFixture Whether to skip fixture generation.
     * @param bool $force Whether to overwrite existing files.
     * @return int|null The exit code.
     */
    public function run(
        string $name,
        string|null $namespace = null,
        string|null $table = null,
        string $connection = ConnectionManager::DEFAULT,
        bool $noEntity = false,
        bool $noFields = false,
        bool $noRelationships = false,
        bool $noValidation = false,
        bool $noRules = false,
        bool $noTest = false,
        bool $noFixture = false,
        bool $force = false
    ): int|null {
        $namespace ??= $this->modelRegistry->getNamespaces()[0] ?? 'App\Models';

        [$namespace, $className] = Make::parseNamespaceClass($namespace, $name.'Model');

        $classAlias = substr($className, 0, -5);
        $entityName = $this->inflector->singularize($classAlias);
        $entityNamespace = $this->entityLocator->getNamespaces()[0] ?? static::inferNamespace($namespace, 'Entities');
        [$entityNamespace, $entityClass] = Make::parseNamespaceClass($entityNamespace, $entityName);
        $enumNamespace = static::inferNamespace($namespace, 'Enums');
        $tableName = $table ?? $this->inflector->underscore($classAlias);
        $schemaTable = null;

        if (!$this->connectionManager->hasConfig($connection)) {
            if ($table !== null) {
                $this->io->error('Database connection config not found.');

                return static::CODE_ERROR;
            }
        } else {
            try {
                $schema = $this->connectionManager->use($connection) |> $this->schemaRegistry->use(...);
            } catch (InvalidArgumentException) {
                if ($table !== null) {
                    $this->io->error('Database schema handler not found.');

                    return static::CODE_ERROR;
                }

                $schema = null;
            }

            if ($schema && $schema->hasTable($tableName)) {
                $schemaTable = $schema->table($tableName);
            } else if ($schema && $table !== null) {
                $this->io->error('Database table not found.');

                return static::CODE_ERROR;
            }
        }

        $fields = $schemaTable && !$noFields ?
            $schemaTable->columns()->values()->toArray() :
            [];
        $indexes = $schemaTable && !$noFields && !$noRules ?
            $schemaTable->indexes()->values()->toArray() :
            [];

        $batch = new GenerationBatch();

        try {
            $relationships = $schemaTable && !$noRelationships ?
                $this->modelSourceBuilder->inferRelationships($schemaTable, $classAlias) :
                [];

            if ($schemaTable && !$noRelationships) {
                $warnings = $this->modelSourceBuilder->getWarnings();

                foreach ($warnings as $warning) {
                    $this->io->warning($warning);
                }
            }

            $enums = $this->modelSourceBuilder->inferEnums($fields, $entityClass);

            $this->buildModelFile(
                namespace: $namespace,
                className: $className,
                entityNamespace: $entityNamespace,
                entityClass: $entityClass,
                enumNamespace: $enumNamespace,
                fields: $fields,
                indexes: $indexes,
                enums: $enums,
                relationships: $relationships,
                connection: $connection,
                table: $table !== null ? $tableName : null,
                withValidation: !$noValidation,
                withRules: !$noRules,
                force: $force
            ) |> $batch->addFile(...);

            foreach ($enums as $enum) {
                $this->buildEnumFile($enumNamespace, $enum, $force) |> $batch->addFile(...);
            }

            if (!$noEntity) {
                $this->buildEntityFile(
                    $entityNamespace,
                    $entityClass,
                    $fields,
                    $relationships,
                    $force
                ) |> $batch->addFile(...);
            }

            if (!$noFixture) {
                $fixtureNamespace = $this->fixtureRegistry->getNamespaces()[0] ?? 'Tests\Fixtures';
                $this->buildFixtureFile($fixtureNamespace, $classAlias, $force) |> $batch->addFile(...);
            }

            if (!$noTest) {
                $testNamespace = 'Tests\TestCase';
                $this->buildTestFile(
                    $testNamespace,
                    $classAlias,
                    $noFixture ? null : $classAlias,
                    $force
                ) |> $batch->addFile(...);
            }
        } catch (InvalidArgumentException $e) {
            $e->getMessage() |> $this->io->error(...);

            return static::CODE_ERROR;
        }

        if (!$batch->save($force)) {
            $this->io->error('Generated files could not be written.');

            return static::CODE_ERROR;
        }

        $paths = $batch->paths();

        foreach ($paths as $path) {
            sprintf(
                'Generated: %s',
                $path
            ) |> $this->io->success(...);
        }

        return static::CODE_SUCCESS;
    }

    /**
     * Builds an entity file.
     *
     * @param string $namespace The entity namespace.
     * @param string $className The entity class name.
     * @param Column[] $fields The schema fields.
     * @param RelationshipData[] $relationships The inferred relationships.
     * @param bool $force Whether to overwrite an existing file.
     * @return GeneratedFile The generated file.
     *
     * @throws InvalidArgumentException If the namespace cannot be resolved or the file already exists.
     */
    protected function buildEntityFile(
        string $namespace,
        string $className,
        array $fields,
        array $relationships,
        bool $force
    ): GeneratedFile {
        $relationships = $this->entitySourceBuilder->buildInferredRelationshipData(
            $relationships,
            $namespace
        );
        $contents = $this->entitySourceBuilder->build(
            $namespace,
            $className,
            $fields,
            $relationships
        );
        $path = $this->make->findPath($namespace);

        if (!$path) {
            throw new InvalidArgumentException('Namespace path not found.');
        }

        $generatedFile = new GeneratedFile(
            Path::join($path, $className.'.php'),
            $contents
        );

        if (!$generatedFile->isValid($force)) {
            throw new InvalidArgumentException('Entity file already exists.');
        }

        return $generatedFile;
    }

    /**
     * Builds an enum file.
     *
     * @param string $namespace The enum namespace.
     * @param EnumData $enum The enum definition.
     * @param bool $force Whether to overwrite an existing file.
     * @return GeneratedFile The generated file.
     *
     * @throws InvalidArgumentException If the namespace cannot be resolved or the file already exists.
     */
    protected function buildEnumFile(string $namespace, array $enum, bool $force): GeneratedFile
    {
        $contents = $this->enumSourceBuilder->build(
            $namespace,
            $enum['className'],
            $enum['cases']
        );
        $path = $this->make->findPath($namespace);

        if (!$path) {
            throw new InvalidArgumentException('Namespace path not found.');
        }

        $generatedFile = new GeneratedFile(
            Path::join($path, $enum['className'].'.php'),
            $contents
        );

        if (!$generatedFile->isValid($force)) {
            throw new InvalidArgumentException('Enum file already exists.');
        }

        return $generatedFile;
    }

    /**
     * Builds a fixture file.
     *
     * @param string $namespace The fixture namespace.
     * @param string $classAlias The model class alias.
     * @param bool $force Whether to overwrite an existing file.
     * @return GeneratedFile The generated file.
     *
     * @throws InvalidArgumentException If the namespace cannot be resolved or the file already exists.
     */
    protected function buildFixtureFile(string $namespace, string $classAlias, bool $force): GeneratedFile
    {
        [$namespace, $className] = Make::parseNamespaceClass($namespace, $classAlias.'Fixture');
        $contents = $this->fixtureSourceBuilder->build($namespace, $className);
        $path = $this->make->findPath($namespace);

        if (!$path) {
            throw new InvalidArgumentException('Namespace path not found.');
        }

        $generatedFile = new GeneratedFile(
            Path::join($path, $className.'.php'),
            $contents
        );

        if (!$generatedFile->isValid($force)) {
            throw new InvalidArgumentException('Fixture file already exists.');
        }

        return $generatedFile;
    }

    /**
     * Builds a model file.
     *
     * @param string $namespace The model namespace.
     * @param string $className The model class name.
     * @param string $entityNamespace The entity namespace.
     * @param string $entityClass The entity class name.
     * @param string $enumNamespace The enum namespace.
     * @param Column[] $fields The schema fields.
     * @param Index[] $indexes The schema indexes.
     * @param EnumData[] $enums The inferred enum definitions.
     * @param RelationshipData[] $relationships The inferred relationships.
     * @param string $connection The database connection.
     * @param string|null $table The explicitly configured table.
     * @param bool $withValidation Whether to generate validation rules.
     * @param bool $withRules Whether to generate application rules.
     * @param bool $force Whether to overwrite an existing file.
     * @return GeneratedFile The generated file.
     *
     * @throws InvalidArgumentException If the namespace cannot be resolved or the file already exists.
     */
    protected function buildModelFile(
        string $namespace,
        string $className,
        string $entityNamespace,
        string $entityClass,
        string $enumNamespace,
        array $fields,
        array $indexes,
        array $enums,
        array $relationships,
        string $connection,
        string|null $table,
        bool $withValidation,
        bool $withRules,
        bool $force
    ): GeneratedFile {
        $relationships = $this->modelSourceBuilder->buildRelationshipData($relationships, $namespace);
        $contents = $this->modelSourceBuilder->build(
            namespace: $namespace,
            className: $className,
            entityNamespace: $entityNamespace,
            entityClass: $entityClass,
            enumNamespace: $enumNamespace,
            fields: $fields,
            indexes: $indexes,
            enums: $enums,
            relationships: $relationships,
            connection: $connection,
            table: $table,
            withValidation: $withValidation,
            withRules: $withRules
        );
        $path = $this->make->findPath($namespace);

        if (!$path) {
            throw new InvalidArgumentException('Namespace path not found.');
        }

        $generatedFile = new GeneratedFile(
            Path::join($path, $className.'.php'),
            $contents
        );

        if (!$generatedFile->isValid($force)) {
            throw new InvalidArgumentException('Model file already exists.');
        }

        return $generatedFile;
    }

    /**
     * Builds a test file.
     *
     * @param string $namespace The test namespace.
     * @param string $classAlias The model class alias.
     * @param string|null $fixtureAlias The fixture alias.
     * @param bool $force Whether to overwrite an existing file.
     * @return GeneratedFile The generated file.
     *
     * @throws InvalidArgumentException If the namespace cannot be resolved or the file already exists.
     */
    protected function buildTestFile(
        string $namespace,
        string $classAlias,
        string|null $fixtureAlias,
        bool $force
    ): GeneratedFile {
        $className = $classAlias.'ModelTest';
        $contents = $this->testSourceBuilder->build($namespace, $className, $fixtureAlias);
        $path = $this->make->findPath($namespace);

        if (!$path) {
            throw new InvalidArgumentException('Test file namespace path not found.');
        }

        $generatedFile = new GeneratedFile(
            Path::join($path, $className.'.php'),
            $contents
        );

        if (!$generatedFile->isValid($force)) {
            throw new InvalidArgumentException('Test file already exists.');
        }

        return $generatedFile;
    }

    /**
     * Infers a sibling namespace.
     *
     * @param string $namespace The namespace.
     * @param string $to The target segment.
     * @return string The inferred namespace.
     */
    protected static function inferNamespace(string $namespace, string $to): string
    {
        return preg_replace('/\\\\Models\z/', '\\'.$to, $namespace) ?? $namespace;
    }
}
