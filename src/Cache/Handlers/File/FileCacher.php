<?php
declare(strict_types=1);

namespace Fyre\Cache\Handlers\File;

use DirectoryIterator;
use Fyre\Cache\Cacher;
use Fyre\Cache\Lock;
use Fyre\Core\Attributes\SensitiveProperty;
use Fyre\Core\Attributes\SensitivePropertyArray;
use Fyre\Utility\Path;
use Override;
use RuntimeException;

use function chmod;
use function fclose;
use function fflush;
use function file_exists;
use function flock;
use function fopen;
use function ftruncate;
use function fwrite;
use function is_dir;
use function is_numeric;
use function is_resource;
use function mkdir;
use function rewind;
use function serialize;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function stream_get_contents;
use function substr;
use function time;
use function unlink;

use const LOCK_EX;
use const LOCK_SH;

/**
 * Caches values on the filesystem.
 */
class FileCacher extends Cacher
{
    /**
     * @var array<string, mixed>
     */
    #[Override]
    protected static array $defaults = [
        'path' => '/tmp/cache',
        'mode' => 0640,
    ];

    /**
     * @var array<string, mixed>
     */
    #[Override]
    #[SensitivePropertyArray(['path'])]
    protected array $config;

    #[SensitiveProperty]
    protected string $path;

    /**
     * Constructs a FileCacher.
     *
     * @param array<string, mixed> $options The Cacher options.
     *
     * @throws RuntimeException If the cache path cannot be created or the prefix is invalid.
     */
    public function __construct(array $options = [])
    {
        parent::__construct($options);

        $this->path = Path::resolve($this->config['path']);

        if (!is_dir($this->path) && !mkdir($this->path, 0777, true)) {
            throw new RuntimeException(sprintf(
                'Folder `%s` could not be created.',
                $this->path
            ));
        }

        if ($this->config['prefix'] && str_contains($this->config['prefix'], DIRECTORY_SEPARATOR)) {
            throw new RuntimeException(sprintf(
                'Cache prefix `%s` is not valid.',
                $this->config['prefix']
            ));
        }
    }

    /**
     * {@inheritDoc}
     *
     * Note: When a prefix is configured, only matching cache files are removed.
     */
    #[Override]
    public function clear(): bool
    {
        $iterator = new DirectoryIterator($this->path);

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                continue;
            }

            if ($this->config['prefix'] && !str_starts_with($item->getBasename(), $this->config['prefix'])) {
                continue;
            }

            $filePath = $item->getPathname();
            @unlink($filePath);
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function delete(string $key): bool
    {
        $filePath = $this->prepareKey($key) |> Path::join($this->path, ?);

        if (!file_exists($filePath)) {
            return false;
        }

        @unlink($filePath);

        return true;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function get(string $key, mixed $default = null): mixed
    {
        $filePath = $this->prepareKey($key) |> Path::join($this->path, ?);

        $handle = @fopen($filePath, 'rb');

        if (!is_resource($handle)) {
            return $default;
        }

        try {
            if (!@flock($handle, LOCK_SH)) {
                return $default;
            }

            $entry = $this->readEntry($handle);
        } finally {
            @fclose($handle);
        }

        if ($entry === false) {
            return $default;
        }

        if ($entry === null || $entry->isExpired()) {
            @unlink($filePath);

            return $default;
        }

        return $entry->getData();
    }

    /**
     * {@inheritDoc}
     *
     * Note: Values are treated as expired when `expires` is less than or equal to the current time.
     */
    #[Override]
    public function increment(string $key, int $amount = 1): false|int
    {
        $filePath = $this->prepareKey($key) |> Path::join($this->path, ?);

        $chmod = !file_exists($filePath);

        $handle = @fopen($filePath, 'c+b');

        if (!is_resource($handle)) {
            return false;
        }

        try {
            if (!@flock($handle, LOCK_EX)) {
                return false;
            }

            $entry = $this->readEntry($handle);

            if ($entry === false) {
                return false;
            }

            if ($entry === null || $entry->isExpired()) {
                $entry = new CacheEntry(0, null);
            }

            $value = $entry->getData();

            if (!is_numeric($value)) {
                return false;
            }

            $value = (int) $value + $amount;

            if (!$this->writeEntry(
                $handle,
                new CacheEntry($value, $entry->getExpires())
            )) {
                return false;
            }
        } finally {
            @fclose($handle);
        }

        if ($chmod) {
            @chmod($filePath, $this->config['mode']);
        }

        return $value;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function lock(string $key, int $expires = 30): Lock
    {
        $key = $this->prepareLockKey($key);

        return new FileLock(
            Path::join($this->path, $key),
            $expires,
            $this->config['mode']
        );
    }

    /**
     * Reads a cache entry.
     *
     * @param resource $handle The file handle.
     * @return CacheEntry|false|null The cache entry, false on failure, or null if the data is not valid.
     */
    protected function readEntry(mixed $handle): CacheEntry|false|null
    {
        if (!@rewind($handle)) {
            return false;
        }

        $contents = @stream_get_contents($handle);

        if ($contents === false) {
            return false;
        }

        return CacheEntry::createFromString($contents);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    protected function setValue(string $key, mixed $value, int|null $expires): bool
    {
        $filePath = Path::join($this->path, $key);

        if ($expires !== null) {
            $expires += time();
        }

        $chmod = !file_exists($filePath);

        $handle = @fopen($filePath, 'c+b');

        if (!is_resource($handle)) {
            return false;
        }

        try {
            if (
                !@flock($handle, LOCK_EX) ||
                !$this->writeEntry($handle, new CacheEntry($value, $expires))
            ) {
                return false;
            }
        } finally {
            @fclose($handle);
        }

        if ($chmod) {
            @chmod($filePath, $this->config['mode']);
        }

        return true;
    }

    /**
     * Writes a cache entry.
     *
     * @param resource $handle The file handle.
     * @param CacheEntry $entry The cache entry.
     * @return bool Whether the cache entry was written.
     */
    protected function writeEntry(mixed $handle, CacheEntry $entry): bool
    {
        $data = serialize($entry);

        if (!@rewind($handle) || !@ftruncate($handle, 0)) {
            return false;
        }

        while ($data !== '') {
            $written = @fwrite($handle, $data);

            if ($written === false || $written === 0) {
                return false;
            }

            $data = substr($data, $written);
        }

        return @fflush($handle);
    }
}
