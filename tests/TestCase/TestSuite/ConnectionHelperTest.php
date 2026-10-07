<?php
declare(strict_types=1);

namespace Tests\TestCase\TestSuite;

use Fyre\Core\Config;
use Fyre\Core\Container;
use Fyre\DB\ConnectionManager;
use Fyre\Event\EventManager;
use Fyre\TestSuite\ConnectionHelper;
use InvalidArgumentException;
use LogicException;
use Override;
use PHPUnit\Framework\TestCase;
use Tests\Mock\DB\TestMysqlConnection;

final class ConnectionHelperTest extends TestCase
{
    protected ConnectionManager $connectionManager;

    public function testAddTestAliases(): void
    {
        $this->connectionManager->setConfig('test', [
            'className' => TestMysqlConnection::class,
        ]);
        $this->connectionManager->setConfig('reporting', [
            'className' => TestMysqlConnection::class,
        ]);
        $this->connectionManager->setConfig('test_reporting', [
            'className' => TestMysqlConnection::class,
        ]);

        ConnectionHelper::addTestAliases($this->connectionManager);

        $this->assertSame(
            [
                'default' => 'test',
                'reporting' => 'test_reporting',
            ],
            $this->connectionManager->getAliases()
        );
        $this->assertSame($this->connectionManager->use('test'), $this->connectionManager->use());
        $this->assertSame($this->connectionManager->use('test_reporting'), $this->connectionManager->use('reporting'));
        $this->assertTrue($this->connectionManager->isLoaded());

        ConnectionHelper::addTestAliases($this->connectionManager);
    }

    public function testAddTestAliasesLoadedConnection(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('Database connection `default` is already loaded.');

        $this->connectionManager->setConfig('test', [
            'className' => TestMysqlConnection::class,
        ]);
        $this->connectionManager->use();

        ConnectionHelper::addTestAliases($this->connectionManager);
    }

    public function testAddTestAliasesMissingTarget(): void
    {
        $this->connectionManager->setConfig('test', [
            'className' => TestMysqlConnection::class,
        ]);
        $this->connectionManager->setConfig('reporting', [
            'className' => TestMysqlConnection::class,
        ]);

        try {
            ConnectionHelper::addTestAliases($this->connectionManager);

            $this->fail('Expected a missing test configuration to fail.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(
                'Test database connection config `test_reporting` does not exist or is aliased.',
                $e->getMessage()
            );
        }

        $this->assertSame([], $this->connectionManager->getAliases());
    }

    public function testAssertTestConnection(): void
    {
        $this->connectionManager->setConfig('test', [
            'className' => TestMysqlConnection::class,
        ]);
        $connection = $this->connectionManager->use('test');

        ConnectionHelper::assertTestConnection($this->connectionManager, $connection);

        $this->assertFalse($this->connectionManager->isLoaded());
    }

    public function testAssertTestConnectionRejectsDefault(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('Fixtures require a configured test database connection. Call ConnectionHelper::addTestAliases() before resolving models.');

        ConnectionHelper::assertTestConnection($this->connectionManager, $this->connectionManager->use());
    }

    #[Override]
    protected function setUp(): void
    {
        $container = new Container();
        $container->singleton(Config::class);
        $container->singleton(EventManager::class);

        $container->use(Config::class)->set('Database.default', [
            'className' => TestMysqlConnection::class,
        ]);

        $this->connectionManager = $container->build(ConnectionManager::class);
    }
}
