<?php
declare(strict_types=1);

namespace Fyre\Commands;

use Fyre\Console\Command;
use Fyre\Console\Console;
use Fyre\Core\Config;
use Fyre\Core\Make;
use Fyre\Core\Make\GeneratedFile;
use Fyre\Utility\Path;
use Override;

use function file_exists;
use function is_dir;
use function sprintf;

/**
 * Implements the make config console command.
 *
 * Generates a config file using the `config` stub.
 */
class MakeConfigCommand extends Command
{
    #[Override]
    protected readonly string|null $alias = 'make:config';

    #[Override]
    protected readonly string $description = 'Generate a new config file.';

    #[Override]
    protected readonly array $options = [
        'file' => [
            'help' => 'Name of the config file to generate.',
            'text' => 'Please enter the config file',
            'required' => true,
        ],
        'path' => [
            'help' => 'Base config path.',
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
     * @param Config $config The Config.
     */
    public function __construct(
        Console $io,
        protected Config $config,
    ) {
        parent::__construct($io);
    }

    /**
     * Runs the command.
     *
     * Note: The path defaults to the first configured {@see Config} path.
     *
     * @param string $file The file name.
     * @param string|null $path The file path.
     * @param bool $force Whether to overwrite an existing file.
     * @return int|null The exit code.
     */
    public function run(string $file, string|null $path = null, bool $force = false): int|null
    {
        $path ??= $this->config->getPaths()[0] ?? '';

        if (file_exists($path) && !is_dir($path)) {
            $this->io->error('Invalid config path.');

            return static::CODE_ERROR;
        }

        $file = Make::normalizePath($file);

        $fullPath = Path::join($path, $file.'.php');
        $contents = Make::loadStub('config');
        $generatedFile = new GeneratedFile($fullPath, $contents);

        if (!$generatedFile->isValid($force)) {
            $this->io->error('Config file already exists.');

            return static::CODE_ERROR;
        }

        if (!$generatedFile->save()) {
            $this->io->error('Config file could not be written.');

            return static::CODE_ERROR;
        }

        sprintf(
            'Generated: %s',
            $generatedFile->getPath()
        ) |> $this->io->success(...);

        return static::CODE_SUCCESS;
    }
}
