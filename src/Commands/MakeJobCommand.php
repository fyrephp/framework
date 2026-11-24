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
 * Implements the make job console command.
 *
 * Generates a job class using the `job` stub.
 */
class MakeJobCommand extends Command
{
    #[Override]
    protected readonly string|null $alias = 'make:job';

    #[Override]
    protected readonly string $description = 'Generate a new job.';

    #[Override]
    protected readonly array $options = [
        'name' => [
            'help' => 'Name of the job to generate.',
            'text' => 'Please enter a name for the job',
            'required' => true,
        ],
        'namespace' => [
            'help' => 'Namespace for the generated job.',
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
     * Note: The namespace defaults to `App\Jobs`, and the generated class name is suffixed with `Job`.
     *
     * @param string $name The job name.
     * @param string|null $namespace The job namespace.
     * @param bool $force Whether to overwrite an existing file.
     * @return int|null The exit code.
     */
    public function run(string $name, string|null $namespace = null, bool $force = false): int|null
    {
        $namespace ??= 'App\Jobs';

        [$namespace, $className] = Make::parseNamespaceClass($namespace, $name.'Job');

        $contents = Make::loadStub('job', [
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
            $this->io->error('Job file already exists.');

            return static::CODE_ERROR;
        }

        if (!$generatedFile->save()) {
            $this->io->error('Job file could not be written.');

            return static::CODE_ERROR;
        }

        sprintf(
            'Generated: %s',
            $generatedFile->getPath()
        ) |> $this->io->success(...);

        return static::CODE_SUCCESS;
    }
}
