<?php
declare(strict_types=1);

namespace Tests\TestCase\Shared;

use Fyre\Core\Container;
use Fyre\DB\Connection;
use Fyre\DB\ConnectionManager;
use Override;
use PHPUnit\Framework\Attributes\After;

use function gc_collect_cycles;

trait DatabaseLifecycleTrait
{
    protected static Connection|null $schemaConnection = null;

    abstract protected static function buildContainer(): Container;

    abstract protected static function clearSchema(Connection $db): void;

    abstract protected static function createSchema(Connection $db): void;

    #[After]
    protected function releaseDatabaseResults(): void
    {
        // An unreachable ORM result can still hold its PDO statement and connection.
        gc_collect_cycles();
    }

    protected static function setUpSchema(): void
    {
        $container = static::buildContainer();
        $connection = $container->use(ConnectionManager::class)->use();

        static::$schemaConnection = $connection;

        static::clearSchema($connection);
        static::createSchema($connection);
    }

    protected static function tearDownSchema(): void
    {
        $connection = static::$schemaConnection;

        if ($connection === null) {
            return;
        }

        try {
            static::clearSchema($connection);
        } finally {
            $connection->disconnect();
            static::$schemaConnection = null;
        }
    }

    #[Override]
    public static function setUpBeforeClass(): void
    {
        static::setUpSchema();
    }

    #[Override]
    public static function tearDownAfterClass(): void
    {
        static::tearDownSchema();
    }
}
