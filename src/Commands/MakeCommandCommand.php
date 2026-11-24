<?php
declare(strict_types=1);

namespace Fyre\Commands;

use Fyre\Console\Command;
use Fyre\Console\CommandRunner;
use Fyre\Console\Console;
use Fyre\Core\Make;
use Fyre\Core\Make\GeneratedFile;
use Fyre\Utility\Path;
use Override;

use function preg_replace;
use function sprintf;
use function strtolower;

/**
 * Implements the make command console command.
 *
 * Generates a console command class using the `command` stub.
 */
class MakeCommandCommand extends Command
{
    #[Override]
    protected readonly string|null $alias = 'make:command';

    #[Override]
    protected readonly string $description = 'Generate a new command.';

    #[Override]
    protected readonly array $options = [
        'name' => [
            'help' => 'Name of the command to generate.',
            'text' => 'Please enter a name for the command',
            'required' => true,
        ],
        'alias' => [
            'help' => 'Alias for the generated command.',
        ],
        'description' => [
            'help' => 'Description for the generated command.',
        ],
        'namespace' => [
            'help' => 'Namespace for the generated command.',
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
     * @param CommandRunner $commandRunner The CommandRunner.
     */
    public function __construct(
        Console $io,
        protected Make $make,
        protected CommandRunner $commandRunner,
    ) {
        parent::__construct($io);
    }

    /**
     * Runs the command.
     *
     * Note: The namespace defaults to the first registered {@see CommandRunner} namespace, or `App\Commands`.
     * When no alias is supplied, an alias is generated from the class name.
     *
     * @param string $name The command name.
     * @param string|null $alias The command alias.
     * @param string|null $description The command description.
     * @param string|null $namespace The command namespace.
     * @param bool $force Whether to overwrite an existing file.
     * @return int|null The exit code.
     */
    public function run(string $name, string|null $alias = null, string|null $description = null, string|null $namespace = null, bool $force = false): int|null
    {
        $namespace ??= $this->commandRunner->getNamespaces()[0] ?? 'App\Commands';

        [$namespace, $className] = Make::parseNamespaceClass($namespace, $name.'Command');

        $command = (string) preg_replace('/Command\z/', '', $className);
        $alias ??= ((string) preg_replace('/(?!\A)([A-Z]+)/', '_$1', $command)) |> strtolower(...);

        $contents = Make::loadStub('command', [
            '{namespace}' => $namespace,
            '{class}' => $className,
            '{alias}' => $alias,
            '{description}' => $description ?? '',
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
            $this->io->error('Command file already exists.');

            return static::CODE_ERROR;
        }

        if (!$generatedFile->save()) {
            $this->io->error('Command file could not be written.');

            return static::CODE_ERROR;
        }

        sprintf(
            'Generated: %s',
            $generatedFile->getPath()
        ) |> $this->io->success(...);

        return static::CODE_SUCCESS;
    }
}
