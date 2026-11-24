<?php
declare(strict_types=1);

namespace Fyre\Commands;

use Fyre\Console\Command;
use Fyre\Console\Console;
use Fyre\Core\Make;
use Fyre\Core\Make\GeneratedFile;
use Fyre\Utility\Path;
use Fyre\View\CellRegistry;
use Override;

use function sprintf;

/**
 * Implements the make cell console command.
 *
 * Generates a cell class using the `cell` stub.
 */
class MakeCellCommand extends Command
{
    #[Override]
    protected readonly string|null $alias = 'make:cell';

    #[Override]
    protected readonly string $description = 'Generate a new cell.';

    #[Override]
    protected readonly array $options = [
        'name' => [
            'help' => 'Name of the cell to generate.',
            'text' => 'Please enter a name for the cell',
            'required' => true,
        ],
        'method' => [
            'help' => 'Method generated for the cell.',
            'default' => 'display',
        ],
        'namespace' => [
            'help' => 'Namespace for the generated cell.',
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
     * @param CellRegistry $cellRegistry The CellRegistry.
     */
    public function __construct(
        Console $io,
        protected Make $make,
        protected CellRegistry $cellRegistry,
    ) {
        parent::__construct($io);
    }

    /**
     * Runs the command.
     *
     * Note: The namespace defaults to the first registered {@see CellRegistry} namespace, or `App\Cells`.
     * The generated class name is suffixed with `Cell`.
     *
     * @param string $name The cell name.
     * @param string $method The cell method.
     * @param string|null $namespace The cell namespace.
     * @param bool $force Whether to overwrite an existing file.
     * @return int|null The exit code.
     */
    public function run(string $name, string $method, string|null $namespace = null, bool $force = false): int|null
    {
        $namespace ??= $this->cellRegistry->getNamespaces()[0] ?? 'App\Cells';

        [$namespace, $className] = Make::parseNamespaceClass($namespace, $name.'Cell');

        $contents = Make::loadStub('cell', [
            '{namespace}' => $namespace,
            '{class}' => $className,
            '{method}' => $method,
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
            $this->io->error('Cell file already exists.');

            return static::CODE_ERROR;
        }

        if (!$generatedFile->save()) {
            $this->io->error('Cell file could not be written.');

            return static::CODE_ERROR;
        }

        sprintf(
            'Generated: %s',
            $generatedFile->getPath()
        ) |> $this->io->success(...);

        return static::CODE_SUCCESS;
    }
}
