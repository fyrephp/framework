<?php
declare(strict_types=1);

namespace Fyre\Commands;

use Fyre\Auth\PolicyRegistry;
use Fyre\Console\Command;
use Fyre\Console\Console;
use Fyre\Core\Make;
use Fyre\Core\Make\GeneratedFile;
use Fyre\Utility\Path;
use Override;

use function sprintf;

/**
 * Implements the make policy console command.
 *
 * Generates a policy class using the `policy` stub.
 */
class MakePolicyCommand extends Command
{
    #[Override]
    protected readonly string|null $alias = 'make:policy';

    #[Override]
    protected readonly string $description = 'Generate a new policy.';

    #[Override]
    protected readonly array $options = [
        'name' => [
            'help' => 'Name of the policy to generate.',
            'text' => 'Please enter a name for the policy',
            'required' => true,
        ],
        'namespace' => [
            'help' => 'Namespace for the generated policy.',
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
     * @param PolicyRegistry $policyRegistry The PolicyRegistry.
     */
    public function __construct(
        Console $io,
        protected Make $make,
        protected PolicyRegistry $policyRegistry,
    ) {
        parent::__construct($io);
    }

    /**
     * Runs the command.
     *
     * Note: The namespace defaults to the first registered {@see PolicyRegistry} namespace, or `App\Policies`.
     * The generated class name is suffixed with `Policy`.
     *
     * @param string $name The policy name.
     * @param string|null $namespace The policy namespace.
     * @param bool $force Whether to overwrite an existing file.
     * @return int|null The exit code.
     */
    public function run(string $name, string|null $namespace = null, bool $force = false): int|null
    {
        $namespace ??= $this->policyRegistry->getNamespaces()[0] ?? 'App\Policies';

        [$namespace, $className] = Make::parseNamespaceClass($namespace, $name.'Policy');

        $contents = Make::loadStub('policy', [
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
            $this->io->error('Policy file already exists.');

            return static::CODE_ERROR;
        }

        if (!$generatedFile->save()) {
            $this->io->error('Policy file could not be written.');

            return static::CODE_ERROR;
        }

        sprintf(
            'Generated: %s',
            $generatedFile->getPath()
        ) |> $this->io->success(...);

        return static::CODE_SUCCESS;
    }
}
