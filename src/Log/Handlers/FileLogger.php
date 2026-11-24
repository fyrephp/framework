<?php
declare(strict_types=1);

namespace Fyre\Log\Handlers;

use Fyre\Core\Attributes\SensitiveProperty;
use Fyre\Core\Attributes\SensitivePropertyArray;
use Fyre\Log\Logger;
use Fyre\Utility\Path;
use InvalidArgumentException;
use Override;
use RuntimeException;
use Stringable;

use function chmod;
use function copy;
use function fclose;
use function file_exists;
use function filesize;
use function flock;
use function fopen;
use function ftruncate;
use function fwrite;
use function is_dir;
use function is_resource;
use function mkdir;
use function rewind;
use function sprintf;
use function sys_get_temp_dir;
use function time;

use const LOCK_EX;
use const PHP_EOL;
use const PHP_SAPI;

/**
 * Writes messages to a file.
 *
 * Note: Log files are rotated by copying the current file when it reaches `maxSize`, then truncating the
 * original file in place.
 */
class FileLogger extends Logger
{
    /**
     * @var array<string, mixed>
     */
    #[Override]
    #[SensitivePropertyArray(['path'])]
    protected static array $defaults = [
        'path' => null,
        'file' => null,
        'suffix' => null,
        'extension' => 'log',
        'maxSize' => 1_048_576,
        'mask' => null,
    ];

    #[SensitiveProperty]
    protected string $path;

    /**
     * Constructs a FileLogger.
     *
     * @param array<string, mixed> $options The Logger options.
     *
     * @throws InvalidArgumentException If a file logger option is not valid.
     */
    public function __construct(array $options = [])
    {
        parent::__construct($options);

        if ($this->config['maxSize'] <= 0) {
            throw new InvalidArgumentException('File logger option `maxSize` must be greater than 0.');
        }

        if (PHP_SAPI === 'cli') {
            $this->config['suffix'] ??= '-cli';
        }

        $this->config['path'] ??= Path::join(sys_get_temp_dir(), 'fyre', 'logs');
        $this->path = Path::resolve($this->config['path']);

        if (!is_dir($this->path) && !mkdir($this->path, 0777, true)) {
            throw new RuntimeException(sprintf(
                'Folder `%s` could not be created.',
                $this->path
            ));
        }
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $level = (string) $level;

        $fileBase = ($this->config['file'] ?? $level).
            ($this->config['suffix'] ?? '');
        $extension = $this->config['extension'];
        $file = $fileBase.($extension ? '.'.$extension : '');
        $filePath = Path::join($this->path, $file);

        $chmod = $this->config['mask'] && !file_exists($filePath);
        $handle = @fopen($filePath, 'a');

        if (!is_resource($handle)) {
            return;
        }

        @flock($handle, LOCK_EX);

        if ($chmod) {
            @chmod($filePath, $this->config['mask']);
        }

        if (filesize($filePath) >= $this->config['maxSize']) {
            $oldPath = Path::join($this->path, $fileBase.'.'.time().($extension ? '.'.$extension : ''));

            if (@copy($filePath, $oldPath)) {
                @ftruncate($handle, 0);
                @rewind($handle);
            }
        }

        $message = static::interpolate($message, $context) |> $this->format($level, ?);

        @fwrite($handle, $message.PHP_EOL);
        @fclose($handle);
    }
}
