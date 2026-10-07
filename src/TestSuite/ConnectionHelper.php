<?php
declare(strict_types=1);

namespace Fyre\TestSuite;

use Fyre\DB\Connection;
use Fyre\DB\ConnectionManager;
use InvalidArgumentException;
use LogicException;

use function sprintf;
use function str_starts_with;

/**
 * Configures and checks database connections used by tests.
 */
abstract class ConnectionHelper
{
    /**
     * Maps application connections to explicitly configured test connections.
     *
     * Call before application boot or resolving models. The default connection uses
     * `test`; other connections use `test_<name>`. Configuration is checked before
     * any aliases are added.
     *
     * @param ConnectionManager $connectionManager The ConnectionManager.
     *
     * @throws InvalidArgumentException If a test configuration is missing.
     * @throws LogicException If an application connection is already loaded.
     */
    public static function addTestAliases(ConnectionManager $connectionManager): void
    {
        $aliases = [];

        foreach ($connectionManager->getConfig() ?? [] as $key => $config) {
            if ($key === 'test' || str_starts_with($key, 'test_')) {
                continue;
            }

            $target = $key === ConnectionManager::DEFAULT ? 'test' : 'test_'.$key;

            if (
                !$connectionManager->hasConfig($target) ||
                isset($connectionManager->getAliases()[$target])
            ) {
                throw new InvalidArgumentException(sprintf(
                    'Test database connection config `%s` does not exist or is aliased.',
                    $target
                ));
            }

            if (
                ($connectionManager->getAliases()[$key] ?? null) !== $target &&
                $connectionManager->isLoaded($key)
            ) {
                throw new LogicException(sprintf(
                    'Database connection `%s` is already loaded.',
                    $key
                ));
            }

            $aliases[$key] = $target;
        }

        foreach ($aliases as $alias => $source) {
            $connectionManager->alias($source, $alias);
        }
    }

    /**
     * Requires a fixture connection to be a configured test connection.
     *
     * @param ConnectionManager $connectionManager The ConnectionManager.
     * @param Connection $connection The fixture Connection.
     *
     * @throws LogicException If the connection is not managed under a test name.
     */
    public static function assertTestConnection(ConnectionManager $connectionManager, Connection $connection): void
    {
        foreach ($connectionManager->getConfig() ?? [] as $key => $config) {
            if ($key !== 'test' && !str_starts_with($key, 'test_')) {
                continue;
            }

            if (isset($connectionManager->getAliases()[$key])) {
                continue;
            }

            if ($connectionManager->isLoaded($key) && $connectionManager->use($key) === $connection) {
                return;
            }
        }

        throw new LogicException('Fixtures require a configured test database connection. Call ConnectionHelper::addTestAliases() before resolving models.');
    }
}
