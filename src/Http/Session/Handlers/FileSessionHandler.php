<?php
declare(strict_types=1);

namespace Fyre\Http\Session\Handlers;

use DirectoryIterator;
use Fyre\Core\Attributes\SensitiveProperty;
use Fyre\Http\Session\SessionHandler;
use Fyre\Utility\Path;
use Override;

use function fclose;
use function filemtime;
use function flock;
use function fopen;
use function ftruncate;
use function fwrite;
use function is_dir;
use function is_resource;
use function mkdir;
use function rewind;
use function str_starts_with;
use function stream_get_contents;
use function strlen;
use function substr;
use function time;
use function touch;
use function unlink;

use const LOCK_EX;
use const LOCK_SH;

/**
 * Stores each session as a file under the configured save path. Files are named using the
 * handler prefix + session id.
 */
class FileSessionHandler extends SessionHandler
{
    #[SensitiveProperty]
    protected string $path;

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function destroy(string $sessionId): bool
    {
        if (!static::isValidSessionId($sessionId)) {
            return false;
        }

        $key = $this->prepareKey($sessionId);
        $filePath = Path::join($this->path, $key);

        @unlink($filePath);

        return true;
    }

    /**
     * {@inheritDoc}
     *
     * Only expired regular files matching the configured prefix and a valid session ID are removed.
     */
    #[Override]
    public function gc(int $expires): false|int
    {
        $maxLife = time() - $expires;
        $prefix = $this->config['prefix'];
        $prefixLength = strlen($prefix);

        $iterator = new DirectoryIterator($this->path);

        $deleted = 0;
        foreach ($iterator as $item) {
            if (!$item->isFile() || $item->isLink()) {
                continue;
            }

            $filename = $item->getFilename();

            if (
                !str_starts_with($filename, $prefix) ||
                !static::isValidSessionId(substr($filename, $prefixLength)) ||
                $item->getMTime() >= $maxLife
            ) {
                continue;
            }

            $filePath = $item->getPathname();

            if (@unlink($filePath)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * {@inheritDoc}
     *
     * Ensures the session directory exists.
     */
    #[Override]
    public function open(string $path, string $name): bool
    {
        $this->path = $path;

        if (!is_dir($path) && !mkdir($path, 0700, true)) {
            return false;
        }

        return true;
    }

    /**
     * {@inheritDoc}
     *
     * Note: Missing files return an empty string. Errors are suppressed.
     */
    #[Override]
    public function read(string $sessionId): false|string
    {
        if (!static::isValidSessionId($sessionId)) {
            return false;
        }

        $key = $this->prepareKey($sessionId);
        $filePath = Path::join($this->path, $key);

        $handle = @fopen($filePath, 'rb');

        if (!is_resource($handle)) {
            return '';
        }

        if (!@flock($handle, LOCK_SH)) {
            @fclose($handle);

            return false;
        }

        $data = @stream_get_contents($handle);

        @fclose($handle);

        return $data === false ?
            false :
            $data;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function updateTimestamp(string $sessionId, string $data): bool
    {
        if (!$this->validateId($sessionId)) {
            return false;
        }

        $key = $this->prepareKey($sessionId);
        $filePath = Path::join($this->path, $key);

        return touch($filePath);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function validateId(string $sessionId): bool
    {
        if (!static::isValidSessionId($sessionId)) {
            return false;
        }

        $key = $this->prepareKey($sessionId);
        $filePath = Path::join($this->path, $key);
        $modified = @filemtime($filePath);

        return $modified !== false && $modified >= time() - $this->config['expires'];
    }

    /**
     * {@inheritDoc}
     *
     * Writes are locked with `LOCK_EX` to prevent concurrent file writes.
     */
    #[Override]
    public function write(string $sessionId, string $data): bool
    {
        if (!static::isValidSessionId($sessionId)) {
            return false;
        }

        $key = $this->prepareKey($sessionId);
        $filePath = Path::join($this->path, $key);

        $handle = @fopen($filePath, 'c+b');

        if (!is_resource($handle)) {
            return false;
        }

        if (
            !@flock($handle, LOCK_EX) ||
            !@rewind($handle) ||
            !@ftruncate($handle, 0)
        ) {
            @fclose($handle);

            return false;
        }

        $result = @fwrite($handle, $data) !== false;

        @fclose($handle);

        return $result;
    }
}
