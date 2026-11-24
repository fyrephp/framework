<?php
declare(strict_types=1);

namespace Fyre\Commands;

use Fyre\Console\Command;
use Fyre\Console\Console;
use Fyre\Core\Make;
use Fyre\Core\Make\FixtureSourceBuilder;
use Fyre\Core\Make\GeneratedFile;
use Fyre\ORM\Model;
use Fyre\ORM\ModelRegistry;
use Fyre\TestSuite\Fixture\FixtureRegistry;
use Fyre\Utility\Path;
use Override;

use function sprintf;
use function substr;

/**
 * Implements the make fixture console command.
 *
 * Generates a fixture class using the `fixture` stub.
 */
class MakeFixtureCommand extends Command
{
    #[Override]
    protected readonly string|null $alias = 'make:fixture';

    #[Override]
    protected readonly string $description = 'Generate a new fixture.';

    #[Override]
    protected readonly array $options = [
        'name' => [
            'help' => 'Name of the fixture to generate.',
            'text' => 'Please enter a name for the fixture',
            'required' => true,
        ],
        'namespace' => [
            'help' => 'Namespace for the generated fixture.',
        ],
        'force' => [
            'help' => 'Overwrite an existing file.',
            'as' => 'boolean',
            'default' => false,
        ],
        'data' => [
            'help' => 'Populate the fixture with existing data.',
            'as' => 'boolean',
            'default' => false,
        ],
        'limit' => [
            'help' => 'Maximum rows to include.',
            'as' => 'integer',
            'default' => 10,
        ],
    ];

    /**
     * {@inheritDoc}
     *
     * @param Console $io The Console.
     * @param Make $make The Make.
     * @param FixtureRegistry $fixtureRegistry The FixtureRegistry.
     * @param FixtureSourceBuilder $sourceBuilder The fixture source builder.
     * @param ModelRegistry $modelRegistry The ModelRegistry.
     */
    public function __construct(
        Console $io,
        protected Make $make,
        protected FixtureRegistry $fixtureRegistry,
        protected FixtureSourceBuilder $sourceBuilder,
        protected ModelRegistry $modelRegistry,
    ) {
        parent::__construct($io);
    }

    /**
     * Runs the command.
     *
     * Note: The namespace defaults to the first registered {@see FixtureRegistry} namespace, or `Tests\Fixtures`.
     * The generated class name is suffixed with `Fixture`.
     *
     * @param string $name The fixture name.
     * @param string|null $namespace The fixture namespace.
     * @param bool $force Whether to overwrite an existing file.
     * @param bool $data Whether to populate the fixture with existing data.
     * @param int $limit The maximum number of rows to include.
     * @return int|null The exit code.
     */
    public function run(
        string $name,
        string|null $namespace = null,
        bool $force = false,
        bool $data = false,
        int $limit = 10
    ): int|null {
        $namespace ??= $this->fixtureRegistry->getNamespaces()[0] ?? 'Tests\Fixtures';

        [$namespace, $className] = Make::parseNamespaceClass($namespace, $name.'Fixture');

        $rows = [];

        if ($data) {
            $model = substr($className, 0, -7) |> $this->modelRegistry->use(...);
            $schema = $model->getSchema(Model::READ);
            $rows = $model->getConnection(Model::READ)
                ->select($schema->columnNames())
                ->from($model->getTable())
                ->orderBy($model->getPrimaryKey())
                ->limit($limit)
                ->execute()
                ->decorate(static function(array $row) use ($schema): array {
                    foreach ($row as $column => $value) {
                        $row[$column] = $schema->column($column)
                            ->type()
                            ->fromDatabase($value);
                    }

                    return $row;
                })
                ->all();
        }

        $contents = $this->sourceBuilder->build($namespace, $className, $rows);
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
            $this->io->error('Fixture file already exists.');

            return static::CODE_ERROR;
        }

        if (!$generatedFile->save()) {
            $this->io->error('Fixture file could not be written.');

            return static::CODE_ERROR;
        }

        sprintf(
            'Generated: %s',
            $generatedFile->getPath()
        ) |> $this->io->success(...);

        return static::CODE_SUCCESS;
    }
}
