<?php
declare(strict_types=1);

namespace Fyre\DB;

use Fyre\Core\Config;
use Fyre\Core\Container;
use Fyre\Core\Traits\DebugTrait;
use InvalidArgumentException;
use LogicException;

use function in_array;
use function is_string;
use function is_subclass_of;
use function sprintf;

/**
 * Manages database connection configurations and shared connection instances.
 */
class ConnectionManager
{
    use DebugTrait;

    public const DEFAULT = 'default';

    /**
     * @var array<string, string>
     */
    protected array $aliases = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    protected array $config = [];

    /**
     * @var array<string, Connection>
     */
    protected array $instances = [];

    /**
     * Constructs a ConnectionManager.
     *
     * @param Container $container The Container.
     * @param Config $config The Config.
     */
    public function __construct(
        protected Container $container,
        Config $config
    ) {
        $handlers = $config->get('Database', []);

        foreach ($handlers as $key => $options) {
            $this->setConfig($key, $options);
        }
    }

    /**
     * Aliases a connection name to a configured connection.
     *
     * Aliases must be added before the original connection is loaded.
     * The source must be a configured name, rather than another alias.
     *
     * @param string $source The configured connection name.
     * @param string $alias The name to redirect.
     * @return static The ConnectionManager instance.
     *
     * @throws InvalidArgumentException If the source is missing or is an alias.
     * @throws LogicException If the original connection is already loaded.
     */
    public function alias(string $source, string $alias): static
    {
        if (
            $source === $alias ||
            !isset($this->config[$source]) ||
            isset($this->aliases[$source])
        ) {
            throw new InvalidArgumentException(sprintf(
                'Database connection alias source `%s` is not valid.',
                $source
            ));
        }

        if (in_array($alias, $this->aliases, true)) {
            throw new InvalidArgumentException(sprintf(
                'Database connection `%s` is already an alias source.',
                $alias
            ));
        }

        if (($this->aliases[$alias] ?? null) === $source) {
            return $this;
        }

        if ($this->isLoaded($alias)) {
            throw new LogicException(sprintf(
                'Database connection `%s` is already loaded.',
                $alias
            ));
        }

        $this->aliases[$alias] = $source;

        return $this;
    }

    /**
     * Builds a Connection.
     *
     * @param array<string, mixed> $options The options for the handler.
     * @return Connection The new Connection instance.
     *
     * @throws InvalidArgumentException If the handler is not valid.
     */
    public function build(array $options = []): Connection
    {
        if (
            !isset($options['className']) ||
            !is_string($options['className']) ||
            !is_subclass_of($options['className'], Connection::class)
        ) {
            throw new InvalidArgumentException(sprintf(
                'Database connection `%s` must extend `%s`.',
                $options['className'] ?? '',
                Connection::class
            ));
        }

        /** @var class-string<Connection> $className */
        $className = $options['className'];

        return $this->container->build($className, ['options' => $options]);
    }

    /**
     * Clears configs and instances.
     */
    public function clear(): void
    {
        $this->aliases = [];
        $this->config = [];
        $this->instances = [];
    }

    /**
     * Removes a connection alias.
     *
     * @param string $alias The alias.
     * @return static The ConnectionManager instance.
     */
    public function dropAlias(string $alias): static
    {
        unset($this->aliases[$alias]);

        return $this;
    }

    /**
     * Returns connection aliases and their configured targets.
     *
     * @return array<string, string> The connection aliases.
     */
    public function getAliases(): array
    {
        return $this->aliases;
    }

    /**
     * Returns the handler config.
     *
     * Note: Configuration is returned by its original name, without resolving aliases.
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
     * @return bool Whether the config exists.
     */
    public function hasConfig(string $key = self::DEFAULT): bool
    {
        return isset($this->config[$key]);
    }

    /**
     * Checks whether a handler is loaded.
     *
     * @param string $key The config key.
     * @return bool Whether the handler is loaded.
     */
    public function isLoaded(string $key = self::DEFAULT): bool
    {
        $key = $this->aliases[$key] ?? $key;

        return isset($this->instances[$key]);
    }

    /**
     * Sets handler config.
     *
     * @param string $key The config key.
     * @param array<string, mixed> $options The config options.
     * @return static The ConnectionManager instance.
     *
     * @throws InvalidArgumentException If the config already exists.
     */
    public function setConfig(string $key, array $options): static
    {
        if (isset($this->config[$key])) {
            throw new InvalidArgumentException(sprintf(
                'Database connection config `%s` already exists.',
                $key
            ));
        }

        $this->config[$key] = $options;

        return $this;
    }

    /**
     * Unloads a handler.
     *
     * @param string $key The config key.
     * @return static The ConnectionManager instance.
     */
    public function unload(string $key = self::DEFAULT): static
    {
        unset($this->instances[$key], $this->config[$key]);

        return $this;
    }

    /**
     * Loads a shared handler instance.
     *
     * @param string $key The config key.
     * @return Connection The Connection instance.
     */
    public function use(string $key = self::DEFAULT): Connection
    {
        $key = $this->aliases[$key] ?? $key;

        return $this->instances[$key] ??= $this->build($this->config[$key] ?? []);
    }
}
