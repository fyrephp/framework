<?php
declare(strict_types=1);

namespace Fyre\Commands;

use Fyre\Console\Command;
use Fyre\Console\Console;
use Fyre\Core\Lang;
use Fyre\Core\Make;
use Fyre\Core\Make\GeneratedFile;
use Fyre\Utility\Path;
use Override;

use function file_exists;
use function is_dir;
use function sprintf;

/**
 * Implements the make lang console command.
 *
 * Generates a language file using the `lang` stub.
 */
class MakeLangCommand extends Command
{
    #[Override]
    protected readonly string|null $alias = 'make:lang';

    #[Override]
    protected readonly string $description = 'Generate a new language file.';

    #[Override]
    protected readonly array $options = [
        'file' => [
            'help' => 'Name of the language file to generate.',
            'text' => 'Please enter the language file',
            'required' => true,
        ],
        'language' => [
            'help' => 'Locale for the generated language file.',
        ],
        'path' => [
            'help' => 'Base language path.',
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
     * @param Lang $lang The Lang.
     */
    public function __construct(
        Console $io,
        protected Lang $lang,
    ) {
        parent::__construct($io);
    }

    /**
     * Runs the command.
     *
     * Note: The locale defaults to the {@see Lang} default locale, and the base path defaults to the first configured lang path.
     *
     * @param string $file The file name.
     * @param string|null $language The language.
     * @param string|null $path The file path.
     * @param bool $force Whether to overwrite an existing file.
     * @return int|null The exit code.
     */
    public function run(string $file, string|null $language = null, string|null $path = null, bool $force = false): int|null
    {
        $language ??= $this->lang->getDefaultLocale();
        $path ??= $this->lang->getPaths()[0] ?? '';

        if (file_exists($path) && !is_dir($path)) {
            $this->io->error('Invalid lang path.');

            return static::CODE_ERROR;
        }

        $file = Make::normalizePath($file);

        $fullPath = Path::join($path, $language, $file.'.php');
        $contents = Make::loadStub('lang');
        $generatedFile = new GeneratedFile($fullPath, $contents);

        if (!$generatedFile->isValid($force)) {
            $this->io->error('Lang file already exists.');

            return static::CODE_ERROR;
        }

        if (!$generatedFile->save()) {
            $this->io->error('Lang file could not be written.');

            return static::CODE_ERROR;
        }

        sprintf(
            'Generated: %s',
            $generatedFile->getPath()
        ) |> $this->io->success(...);

        return static::CODE_SUCCESS;
    }
}
