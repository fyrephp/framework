<?php
declare(strict_types=1);

namespace Fyre\Cache;

use Fyre\Cache\Exceptions\InvalidArgumentException;
use Fyre\Cache\Handlers\Null\NullCacher;
use Fyre\Core\Config;
use Fyre\Core\Container;
use Fyre\Core\Traits\DebugTrait;

use function is_string;
use function is_subclass_of;
use function sprintf;

/**
 * Manages cache configurations and shared cache instances.
 *
 * Note: When disabled, valid cache builds resolve to a shared {@see NullCacher}.
 * By default the cache is disabled when `App.debug` is enabled.
 */
class CacheManager
{
    use DebugTrait;

    public const DEFAULT = 'default';

    /**
     * @var array<string, array<string, mixed>>
     */
    protected array $config = [];

    protected bool $enabled = true;

    /**
     * @var array<string, Cacher>
     */
    protected array $instances = [];

    protected NullCacher $nullCacher;

    /**
     * Constructs a CacheManager.
     *
     * @param Container $container The Container.
     * @param Config $config The Config.
     */
    public function __construct(
        protected Container $container,
        Config $config
    ) {
        $handlers = $config->get('Cache', []);

        foreach ($handlers as $key => $options) {
            $this->setConfig($key, $options);
        }

        $this->enabled = !$config->get('App.debug');
    }

    /**
     * Builds a Cacher.
     *
     * @param array<string, mixed> $options The Cacher options.
     * @return Cacher The new Cacher instance.
     *
     * @throws InvalidArgumentException If the cacher is not valid.
     */
    public function build(array $options = []): Cacher
    {
        if (
            !isset($options['className']) ||
            !is_string($options['className']) ||
            !is_subclass_of($options['className'], Cacher::class)
        ) {
            throw new InvalidArgumentException(sprintf(
                'Cacher `%s` must extend `%s`.',
                $options['className'] ?? '',
                Cacher::class
            ));
        }

        if (!$this->enabled) {
            return $this->nullCacher ??= new NullCacher();
        }

        /** @var class-string<Cacher> $className */
        $className = $options['className'];

        return $this->container->build($className, ['options' => $options]);
    }

    /**
     * Clears all instances and configs.
     */
    public function clear(): void
    {
        $this->config = [];
        $this->instances = [];
    }

    /**
     * Disables the cache.
     *
     * @return static The CacheManager instance.
     */
    public function disable(): static
    {
        $this->enabled = false;

        return $this;
    }

    /**
     * Enables the cache.
     *
     * @return static The CacheManager instance.
     */
    public function enable(): static
    {
        $this->enabled = true;

        return $this;
    }

    /**
     * Returns the handler config.
     *
     * @param string|null $key The config key.
     * @return array<string, mixed>|null The config array, or a single config when `$key` is supplied.
     */
    public function getConfig(string|null $key = null): array|null
    {
        if ($key === null) {
            return $this->config;
        }

        return $this->config[$key] ?? null;
    }

    /**
     * Checks whether a config exists.
     *
     * @param string $key The config key.
     * @return bool Whether a config exists.
     */
    public function hasConfig(string $key = self::DEFAULT): bool
    {
        return isset($this->config[$key]);
    }

    /**
     * Checks whether the cache is enabled.
     *
     * @return bool Whether the cache is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Checks whether a handler is loaded.
     *
     * @param string $key The config key.
     * @return bool Whether a handler is loaded.
     */
    public function isLoaded(string $key = self::DEFAULT): bool
    {
        return isset($this->instances[$key]);
    }

    /**
     * Sets handler config.
     *
     * @param string $key The config key.
     * @param array<string, mixed> $options The config options.
     * @return static The CacheManager instance.
     *
     * @throws InvalidArgumentException If the config already exists.
     */
    public function setConfig(string $key, array $options): static
    {
        if (isset($this->config[$key])) {
            throw new InvalidArgumentException(sprintf(
                'Cache config `%s` already exists.',
                $key
            ));
        }

        $this->config[$key] = $options;

        return $this;
    }

    /**
     * Unloads a handler.
     *
     * Note: This removes both the cached instance and the configuration.
     *
     * @param string $key The config key.
     * @return static The CacheManager instance.
     */
    public function unload(string $key = self::DEFAULT): static
    {
        unset($this->instances[$key], $this->config[$key]);

        return $this;
    }

    /**
     * Loads a shared handler instance.
     *
     * Note: If no config exists for the requested key, the cacher is built with an empty config.
     *
     * @param string $key The config key.
     * @return Cacher The Cacher instance.
     */
    public function use(string $key = self::DEFAULT): Cacher
    {
        if (!$this->enabled) {
            return $this->build($this->config[$key] ?? []);
        }

        return $this->instances[$key] ??= $this->build($this->config[$key] ?? []);
    }
}
