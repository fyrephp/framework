<?php
declare(strict_types=1);

namespace Fyre\Cache\Handlers\Memcached;

use DateInterval;
use Fyre\Cache\Cacher;
use Fyre\Cache\Exceptions\CacheException;
use Fyre\Cache\Lock;
use Fyre\Core\Attributes\SensitivePropertyArray;
use Memcached;
use MemcachedException;
use Override;

use function array_combine;
use function array_keys;
use function array_map;
use function in_array;
use function iterator_to_array;
use function sprintf;
use function time;

/**
 * Caches values using Memcached.
 */
class MemcachedCacher extends Cacher
{
    /**
     * @var array<string, mixed>
     */
    #[Override]
    protected static array $defaults = [
        'host' => '127.0.0.1',
        'port' => 11211,
        'weight' => 1,
        'flushServer' => false,
    ];

    /**
     * @var array<string, mixed>
     */
    #[Override]
    #[SensitivePropertyArray([
        'host',
        'port',
    ])]
    protected array $config;

    protected Memcached $connection;

    /**
     * Returns the Memcached expiry value.
     *
     * @param int $expires The expiration interval in seconds.
     * @return int The expiry value.
     */
    public static function getExpiry(int $expires): int
    {
        return $expires > 2_592_000 ?
            time() + $expires :
            $expires;
    }

    /**
     * Constructs a MemcachedCacher.
     *
     * @param array<string, mixed> $options The Cacher options.
     *
     * @throws CacheException If the connection fails.
     */
    public function __construct(array $options)
    {
        parent::__construct($options);

        try {
            $this->connection = new Memcached();

            $this->connection->setOption(Memcached::OPT_BINARY_PROTOCOL, true);

            $this->connection->addServer(
                $this->config['host'],
                (int) $this->config['port'],
                $this->config['weight']
            );

            if (!$this->getStats()) {
                throw new CacheException('Memcache cache connection failed.');
            }
        } catch (MemcachedException $e) {
            throw new CacheException(sprintf(
                'Memcache cache connection error: %s',
                $e->getMessage()
            ), previous: $e);
        }
    }

    /**
     * Releases the Memcached connection.
     */
    public function __destruct()
    {
        $this->connection->quit();
    }

    /**
     * {@inheritDoc}
     *
     * @throws CacheException If the cache has a prefix or server flushing is disabled.
     */
    #[Override]
    public function clear(): bool
    {
        if ($this->config['prefix'] !== '' || $this->config['flushServer'] !== true) {
            throw new CacheException('Memcached cache clear requires an empty prefix and flushServer enabled.');
        }

        return $this->connection->flush();
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function decrement(string $key, int $amount = 1): false|int
    {
        $key = $this->prepareKey($key);

        $this->connection->add($key, 0);

        return $this->connection->decrement($key, $amount, $amount);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function delete(string $key): bool
    {
        return $this->prepareKey($key) |> $this->connection->delete(...);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function deleteMultiple(iterable $keys): bool
    {
        $keys = array_map(
            $this->prepareKey(...),
            iterator_to_array($keys)
        );

        $result = $this->connection->deleteMulti($keys);

        return !in_array(false, $result, true);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->prepareKey($key) |> $this->connection->get(...);

        if ($this->connection->getResultCode() === Memcached::RES_NOTFOUND) {
            return $default;
        }

        return $value;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function increment(string $key, int $amount = 1): false|int
    {
        $key = $this->prepareKey($key);

        $this->connection->add($key, 0);

        return $this->connection->increment($key, $amount, $amount);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function lock(string $key, int $expires = 30): Lock
    {
        return new MemcachedLock(
            $this->connection,
            $this->prepareLockKey($key),
            $expires
        );
    }

    /**
     * {@inheritDoc}
     *
     * @param iterable<string, mixed> $values The values to set.
     */
    #[Override]
    public function setMultiple(iterable $values, DateInterval|int|null $expire = null): bool
    {
        $values = iterator_to_array($values);
        $expires = $this->getExpires($expire);

        if ($expires !== null && $expires <= 0) {
            array_keys($values) |> $this->deleteMultiple(...);

            return true;
        }

        $keys = array_map(
            $this->prepareKey(...),
            array_keys($values)
        );

        $values = array_combine($keys, $values);
        $expires = $expires === null ?
            0 :
            static::getExpiry($expires);

        return $this->connection->setMulti($values, $expires);
    }

    /**
     * Returns memcached stats.
     *
     * @return array<string, mixed>|null The memcached stats for the configured server or null if unavailable.
     */
    protected function getStats(): array|null
    {
        $stats = $this->connection->getStats();

        $server = $this->config['host'].':'.$this->config['port'];

        return $stats[$server] ?? null;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    protected function setValue(string $key, mixed $value, int|null $expires): bool
    {
        $expires = $expires === null ?
            0 :
            static::getExpiry($expires);

        return $this->connection->set($key, $value, $expires);
    }
}
