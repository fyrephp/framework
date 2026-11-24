<?php
declare(strict_types=1);

namespace Fyre\Commands;

use Fyre\Console\Command;
use Fyre\Console\Console;
use Fyre\Core\Make;
use Fyre\Core\Make\GeneratedFile;
use Fyre\Utility\Path;
use Override;

use function sprintf;

/**
 * Implements the make form console command.
 *
 * Generates a form class using the `form` stub.
 */
class MakeFormCommand extends Command
{
    #[Override]
    protected readonly string|null $alias = 'make:form';

    #[Override]
    protected readonly string $description = 'Generate a new form.';

    #[Override]
    protected readonly array $options = [
        'name' => [
            'help' => 'Name of the form to generate.',
            'text' => 'Please enter a name for the form',
            'required' => true,
        ],
        'namespace' => [
            'help' => 'Namespace for the generated form.',
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
     */
    public function __construct(
        Console $io,
        protected Make $make,
    ) {
        parent::__construct($io);
    }

    /**
     * Runs the command.
     *
     * Note: The namespace defaults to `App\Forms`, and the generated class name is suffixed with `Form`.
     *
     * @param string $name The form name.
     * @param string|null $namespace The form namespace.
     * @param bool $force Whether to overwrite an existing file.
     * @return int|null The exit code.
     */
    public function run(string $name, string|null $namespace = null, bool $force = false): int|null
    {
        $namespace ??= 'App\Forms';

        [$namespace, $className] = Make::parseNamespaceClass($namespace, $name.'Form');

        $contents = Make::loadStub('form', [
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
            $this->io->error('Form file already exists.');

            return static::CODE_ERROR;
        }

        if (!$generatedFile->save()) {
            $this->io->error('Form file could not be written.');

            return static::CODE_ERROR;
        }

        sprintf(
            'Generated: %s',
            $generatedFile->getPath()
        ) |> $this->io->success(...);

        return static::CODE_SUCCESS;
    }
}
