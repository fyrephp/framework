<?php
declare(strict_types=1);

namespace Fyre\Commands;

use Fyre\Console\Command;
use Fyre\Console\Console;
use Fyre\Core\Make;
use Fyre\Core\Make\EnumSourceBuilder;
use Fyre\Core\Make\GeneratedFile;
use Fyre\Utility\Path;
use InvalidArgumentException;
use Override;

use function sprintf;

/**
 * Implements the make enum console command.
 *
 * Generates an enum class using the `enum` stub.
 */
class MakeEnumCommand extends Command
{
    #[Override]
    protected readonly string|null $alias = 'make:enum';

    #[Override]
    protected readonly string $description = 'Generate a new enum.';

    #[Override]
    protected readonly array $options = [
        'name' => [
            'help' => 'Name of the enum to generate.',
            'text' => 'Please enter a name for the enum',
            'required' => true,
        ],
        'cases' => [
            'help' => 'Comma-separated enum cases.',
        ],
        'namespace' => [
            'help' => 'Namespace for the generated enum.',
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
     * @param EnumSourceBuilder $sourceBuilder The enum source builder.
     */
    public function __construct(
        Console $io,
        protected Make $make,
        protected EnumSourceBuilder $sourceBuilder,
    ) {
        parent::__construct($io);
    }

    /**
     * Runs the command.
     *
     * Note: The namespace defaults to `App\Enums`. Cases are comma-separated and may use `case:value` syntax.
     * An explicit value generates a string-backed enum.
     *
     * @param string $name The enum name.
     * @param string|null $cases The enum cases.
     * @param string|null $namespace The enum namespace.
     * @param bool $force Whether to overwrite an existing file.
     * @return int|null The exit code.
     */
    public function run(
        string $name,
        string|null $cases = null,
        string|null $namespace = null,
        bool $force = false
    ): int|null {
        $namespace ??= 'App\Enums';

        [$namespace, $className] = Make::parseNamespaceClass($namespace, $name);

        try {
            $contents = $this->sourceBuilder->build($namespace, $className, $cases);
        } catch (InvalidArgumentException $e) {
            $e->getMessage() |> $this->io->error(...);

            return static::CODE_ERROR;
        }

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
            $this->io->error('Enum file already exists.');

            return static::CODE_ERROR;
        }

        if (!$generatedFile->save()) {
            $this->io->error('Enum file could not be written.');

            return static::CODE_ERROR;
        }

        sprintf(
            'Generated: %s',
            $generatedFile->getPath()
        ) |> $this->io->success(...);

        return static::CODE_SUCCESS;
    }
}
