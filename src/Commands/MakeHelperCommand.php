<?php
declare(strict_types=1);

namespace Fyre\Commands;

use Fyre\Console\Command;
use Fyre\Console\Console;
use Fyre\Core\Make;
use Fyre\Core\Make\GeneratedFile;
use Fyre\Utility\Path;
use Fyre\View\HelperRegistry;
use Override;

use function sprintf;

/**
 * Implements the make helper console command.
 *
 * Generates a helper class using the `helper` stub.
 */
class MakeHelperCommand extends Command
{
    #[Override]
    protected readonly string|null $alias = 'make:helper';

    #[Override]
    protected readonly string $description = 'Generate a new helper.';

    #[Override]
    protected readonly array $options = [
        'name' => [
            'help' => 'Name of the helper to generate.',
            'text' => 'Please enter a name for the helper',
            'required' => true,
        ],
        'namespace' => [
            'help' => 'Namespace for the generated helper.',
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
     * @param HelperRegistry $helperRegistry The HelperRegistry.
     */
    public function __construct(
        Console $io,
        protected Make $make,
        protected HelperRegistry $helperRegistry,
    ) {
        parent::__construct($io);
    }

    /**
     * Runs the command.
     *
     * Note: The namespace defaults to the first registered {@see HelperRegistry} namespace, or `App\Helpers`.
     * The generated class name is suffixed with `Helper`.
     *
     * @param string $name The helper name.
     * @param string|null $namespace The helper namespace.
     * @param bool $force Whether to overwrite an existing file.
     * @return int|null The exit code.
     */
    public function run(string $name, string|null $namespace = null, bool $force = false): int|null
    {
        $namespace ??= $this->helperRegistry->getNamespaces()[0] ?? 'App\Helpers';

        [$namespace, $className] = Make::parseNamespaceClass($namespace, $name.'Helper');

        $contents = Make::loadStub('helper', [
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
            $this->io->error('Helper file already exists.');

            return static::CODE_ERROR;
        }

        if (!$generatedFile->save()) {
            $this->io->error('Helper file could not be written.');

            return static::CODE_ERROR;
        }

        sprintf(
            'Generated: %s',
            $generatedFile->getPath()
        ) |> $this->io->success(...);

        return static::CODE_SUCCESS;
    }
}
