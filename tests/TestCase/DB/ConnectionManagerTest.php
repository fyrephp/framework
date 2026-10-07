<?php
declare(strict_types=1);

namespace Tests\TestCase\DB;

use Fyre\Core\Config;
use Fyre\Core\Container;
use Fyre\DB\ConnectionManager;
use Fyre\DB\Handlers\Mysql\MysqlConnection;
use Fyre\DB\TypeParser;
use Fyre\Event\EventManager;
use Fyre\Log\LogManager;
use InvalidArgumentException;
use Override;
use PHPUnit\Framework\TestCase;
use Tests\Mock\DB\TestMysqlConnection;

use function getenv;

final class ConnectionManagerTest extends TestCase
{
    protected ConnectionManager $connectionManager;

    public function testAliasCannotRedirectAnotherAliasSource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Database connection `test` is already an alias source.');

        $this->connectionManager->setConfig('test', [
            'className' => TestMysqlConnection::class,
        ]);

        $this->connectionManager->alias('test', 'default');

        $this->connectionManager->alias('other', 'test');
    }

    public function testAliasCannotUseAnAliasSource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Database connection alias source `default` is not valid.');

        $this->connectionManager->setConfig('test', [
            'className' => TestMysqlConnection::class,
        ]);

        $this->connectionManager->alias('test', 'default');

        $this->connectionManager->alias('default', 'other');
    }

    public function testAliasMissingSource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Database connection alias source `missing` is not valid.');

        $this->connectionManager->alias('missing', 'default');
    }

    public function testBuildInvalidHandler(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Database connection `Invalid` must extend `Fyre\DB\Connection`.');

        $this->connectionManager->build([
            'className' => 'Invalid',
        ]);
    }

    public function testClear(): void
    {
        $this->connectionManager->clear();
        $this->connectionManager->setConfig('default', [
            'className' => TestMysqlConnection::class,
        ]);
        $this->connectionManager->use();

        $this->connectionManager->clear();

        $this->assertFalse($this->connectionManager->isLoaded());
        $this->assertFalse($this->connectionManager->hasConfig());
    }

    public function testClearRemovesAliases(): void
    {
        $this->connectionManager->setConfig('test', [
            'className' => TestMysqlConnection::class,
        ]);
        $this->connectionManager->alias('test', 'default');

        $this->connectionManager->clear();

        $this->assertSame([], $this->connectionManager->getAliases());
    }

    public function testDropAlias(): void
    {
        $this->connectionManager->unload('default');
        $this->connectionManager->setConfig('default', [
            'className' => TestMysqlConnection::class,
        ]);
        $this->connectionManager->setConfig('test', [
            'className' => TestMysqlConnection::class,
        ]);

        $this->connectionManager->alias('test', 'default');
        $test = $this->connectionManager->use();

        $this->connectionManager->dropAlias('default');

        $this->assertNotSame($test, $this->connectionManager->use());
        $this->assertSame([], $this->connectionManager->getAliases());
    }

    public function testGetConfig(): void
    {
        $config = $this->connectionManager->getConfig();

        $this->assertIsArray($config);
        $this->assertArraysAreIdentical(
            [
                'default' => [
                    'className' => MysqlConnection::class,
                    'host' => getenv('MYSQL_HOST'),
                    'username' => getenv('MYSQL_USERNAME'),
                    'password' => getenv('MYSQL_PASSWORD'),
                    'database' => getenv('MYSQL_DATABASE'),
                    'port' => getenv('MYSQL_PORT'),
                    'collation' => 'utf8mb4_unicode_ci',
                    'charset' => 'utf8mb4',
                    'compress' => true,
                ],
                'other' => [
                    'className' => MysqlConnection::class,
                    'host' => getenv('MYSQL_HOST'),
                    'username' => getenv('MYSQL_USERNAME'),
                    'password' => getenv('MYSQL_PASSWORD'),
                    'database' => getenv('MYSQL_DATABASE'),
                    'port' => getenv('MYSQL_PORT'),
                    'collation' => 'utf8mb4_unicode_ci',
                    'charset' => 'utf8mb4',
                    'compress' => true,
                ],
            ],
            $config
        );
    }

    public function testGetConfigEmptyKey(): void
    {
        $config = [
            'className' => MysqlConnection::class,
        ];

        $this->connectionManager->setConfig('', $config);

        $this->assertSame(
            $config,
            $this->connectionManager->getConfig('')
        );
    }

    public function testGetConfigKey(): void
    {
        $config = $this->connectionManager->getConfig('default');

        $this->assertIsArray($config);
        $this->assertArraysAreIdentical(
            [
                'className' => MysqlConnection::class,
                'host' => getenv('MYSQL_HOST'),
                'username' => getenv('MYSQL_USERNAME'),
                'password' => getenv('MYSQL_PASSWORD'),
                'database' => getenv('MYSQL_DATABASE'),
                'port' => getenv('MYSQL_PORT'),
                'collation' => 'utf8mb4_unicode_ci',
                'charset' => 'utf8mb4',
                'compress' => true,
            ],
            $config
        );
    }

    public function testIsLoaded(): void
    {
        $this->connectionManager->use();

        $this->assertTrue(
            $this->connectionManager->isLoaded()
        );
    }

    public function testIsLoadedInvalid(): void
    {
        $this->assertFalse(
            $this->connectionManager->isLoaded('test')
        );
    }

    public function testIsLoadedKey(): void
    {
        $this->connectionManager->use('other');

        $this->assertTrue(
            $this->connectionManager->isLoaded('other')
        );
    }

    public function testMissingAliasedConnection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Database connection `` must extend `Fyre\DB\Connection`.');

        $this->connectionManager->setConfig('test', [
            'className' => TestMysqlConnection::class,
        ]);

        $this->connectionManager->alias('test', 'default');

        $this->connectionManager->unload('test');

        $this->connectionManager->use();
    }

    public function testSetConfig(): void
    {
        $this->assertSame(
            $this->connectionManager,
            $this->connectionManager->setConfig('test', [
                'className' => MysqlConnection::class,
            ])
        );

        $config = $this->connectionManager->getConfig('test');

        $this->assertIsArray($config);
        $this->assertArraysAreIdentical(
            [
                'className' => MysqlConnection::class,
            ],
            $config
        );

        $this->connectionManager->unload('test');
    }

    public function testSetConfigExists(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Database connection config `default` already exists.');

        $this->connectionManager->setConfig('default', [
            'className' => MysqlConnection::class,
        ]);
    }

    public function testUnload(): void
    {
        $this->connectionManager->use();

        $this->assertSame(
            $this->connectionManager,
            $this->connectionManager->unload()
        );

        $this->assertFalse(
            $this->connectionManager->isLoaded()
        );
        $this->assertFalse(
            $this->connectionManager->hasConfig()
        );
    }

    public function testUnloadInvalid(): void
    {
        $this->assertSame(
            $this->connectionManager,
            $this->connectionManager->unload('test')
        );
    }

    public function testUnloadKey(): void
    {
        $this->connectionManager->use('other');

        $this->assertSame(
            $this->connectionManager,
            $this->connectionManager->unload('other')
        );

        $this->assertFalse(
            $this->connectionManager->isLoaded('other')
        );
        $this->assertFalse(
            $this->connectionManager->hasConfig('other')
        );
    }

    public function testUse(): void
    {
        $handler1 = $this->connectionManager->use();
        $handler2 = $this->connectionManager->use();

        $this->assertSame($handler1, $handler2);

        $this->assertInstanceOf(
            MysqlConnection::class,
            $handler1
        );
    }

    #[Override]
    protected function setUp(): void
    {
        $container = new Container();
        $container->singleton(TypeParser::class);
        $container->singleton(Config::class);
        $container->singleton(EventManager::class);
        $container->singleton(LogManager::class);
        $container->use(Config::class)->set('Database', [
            'default' => [
                'className' => MysqlConnection::class,
                'host' => getenv('MYSQL_HOST'),
                'username' => getenv('MYSQL_USERNAME'),
                'password' => getenv('MYSQL_PASSWORD'),
                'database' => getenv('MYSQL_DATABASE'),
                'port' => getenv('MYSQL_PORT'),
                'collation' => 'utf8mb4_unicode_ci',
                'charset' => 'utf8mb4',
                'compress' => true,
            ],
            'other' => [
                'className' => MysqlConnection::class,
                'host' => getenv('MYSQL_HOST'),
                'username' => getenv('MYSQL_USERNAME'),
                'password' => getenv('MYSQL_PASSWORD'),
                'database' => getenv('MYSQL_DATABASE'),
                'port' => getenv('MYSQL_PORT'),
                'collation' => 'utf8mb4_unicode_ci',
                'charset' => 'utf8mb4',
                'compress' => true,
            ],
        ]);

        $this->connectionManager = $container->use(ConnectionManager::class);
    }
}
