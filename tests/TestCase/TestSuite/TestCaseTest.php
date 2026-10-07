<?php
declare(strict_types=1);

namespace Tests\TestCase\TestSuite\Integration;

use Closure;
use Fyre\Core\Engine;
use Fyre\Core\ErrorHandler;
use Fyre\Core\Loader;
use Fyre\DB\Connection;
use Fyre\DB\ConnectionManager;
use Fyre\ORM\Model;
use Fyre\TestSuite\Fixture\Fixture;
use Fyre\TestSuite\Fixture\FixtureRegistry;
use Fyre\TestSuite\TestCase as FrameworkTestCase;
use LogicException;
use Override;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\SkippedWithMessageException;
use RuntimeException;
use Tests\Mock\Application;

use function spl_object_id;

final class TestCaseTest extends FrameworkTestCase
{
    public function testCleanupUsesEachWriteConnection(): void
    {
        $first = $this->createMock(Connection::class);
        $second = $this->createMock(Connection::class);

        foreach ([$first, $second] as $connection) {
            $connection->expects($this->once())
                ->method('disableForeignKeys')
                ->willReturnSelf();
            $connection->expects($this->once())
                ->method('enableForeignKeys')
                ->willReturnSelf();
            $connection->expects($this->once())
                ->method('truncate')
                ->with('items')
                ->willReturnSelf();
        }

        $test = $this->buildTestCase([$first, $second]);

        Closure::bind(function(): void {
            $this->teardownFixtures();
        }, $test, FrameworkTestCase::class)();
    }

    public function testGroupsSameTableOnDifferentConnections(): void
    {
        $first = $this->createStub(Connection::class);
        $second = $this->createStub(Connection::class);

        $firstModel = $this->createStub(Model::class);
        $firstModel->method('getConnection')->willReturn($first);
        $firstModel->method('getTable')->willReturn('items');

        $secondModel = $this->createStub(Model::class);
        $secondModel->method('getConnection')->willReturn($second);
        $secondModel->method('getTable')->willReturn('items');

        $fixture = $this->getMockBuilder(Fixture::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getModels'])
            ->getMock();
        $fixture->expects($this->once())
            ->method('getModels')
            ->willReturn([$firstModel, $secondModel, $firstModel]);

        $this->assertSame([
            spl_object_id($first) => [
                'connection' => $first,
                'tables' => ['items'],
            ],
            spl_object_id($second) => [
                'connection' => $second,
                'tables' => ['items'],
            ],
        ], $fixture->getTablesByConnection());
    }

    public function testRejectsUnsafeConnectionBeforeChangingData(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('Fixtures require a configured test database connection. Call ConnectionHelper::addTestAliases() before resolving models.');

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('disableForeignKeys');
        $connection->expects($this->never())->method('truncate');

        $fixture = $this->createMock(Fixture::class);
        $fixture->expects($this->never())->method('run');

        $test = $this->buildTestCase([$connection], $fixture, false);

        Closure::bind(function(): void {
            $this->setupFixtures();
        }, $test, FrameworkTestCase::class)();
    }

    public function testRestoresForeignKeysWhenDisablingAnotherConnectionFails(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Disable failure');

        $first = $this->createMock(Connection::class);
        $first->expects($this->once())
            ->method('disableForeignKeys')
            ->willReturnSelf();
        $first->expects($this->once())
            ->method('enableForeignKeys')
            ->willReturnSelf();

        $second = $this->createMock(Connection::class);
        $second->expects($this->once())
            ->method('disableForeignKeys')
            ->willThrowException(new RuntimeException('Disable failure'));
        $second->expects($this->never())->method('enableForeignKeys');

        $fixture = $this->createMock(Fixture::class);
        $fixture->expects($this->never())->method('run');

        $test = $this->buildTestCase([$first, $second], $fixture);

        Closure::bind(function(): void {
            $this->setupFixtures();
        }, $test, FrameworkTestCase::class)();
    }

    public function testRestoresForeignKeysWhenFixtureFails(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Fixture failure');

        $first = $this->createMock(Connection::class);
        $second = $this->createMock(Connection::class);

        foreach ([$first, $second] as $connection) {
            $connection->expects($this->once())
                ->method('disableForeignKeys')
                ->willReturnSelf();
            $connection->expects($this->once())
                ->method('enableForeignKeys')
                ->willReturnSelf();
        }

        $fixture = $this->createMock(Fixture::class);
        $fixture->expects($this->once())
            ->method('run')
            ->willThrowException(new RuntimeException('Fixture failure'));

        $test = $this->buildTestCase([$first, $second], $fixture);

        Closure::bind(function(): void {
            $this->setupFixtures();
        }, $test, FrameworkTestCase::class)();
    }

    public function testRestoresOtherConnectionsWhenEnablingForeignKeysFails(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Restore failure');

        $first = $this->createMock(Connection::class);
        $first->expects($this->once())
            ->method('disableForeignKeys')
            ->willReturnSelf();
        $first->expects($this->once())
            ->method('enableForeignKeys')
            ->willReturnSelf();

        $second = $this->createMock(Connection::class);
        $second->expects($this->once())
            ->method('disableForeignKeys')
            ->willReturnSelf();
        $second->expects($this->once())
            ->method('enableForeignKeys')
            ->willThrowException(new RuntimeException('Restore failure'));

        $fixture = $this->createMock(Fixture::class);
        $fixture->expects($this->once())->method('run');

        $test = $this->buildTestCase([$first, $second], $fixture);

        Closure::bind(function(): void {
            $this->setupFixtures();
        }, $test, FrameworkTestCase::class)();
    }

    public function testSkipIf(): void
    {
        $this->expectException(SkippedWithMessageException::class);

        $this->skipIf(true);
    }

    public function testSkipIfFalse(): void
    {
        $this->expectNotToPerformAssertions();

        $this->skipIf(false);
    }

    public function testSkipUnless(): void
    {
        $this->expectNotToPerformAssertions();

        $this->skipUnless(true);
    }

    public function testSkipUnlessFalse(): void
    {
        $this->expectException(SkippedWithMessageException::class);

        $this->skipUnless(false);
    }

    /**
     * @param Connection[] $connections
     * @param (Fixture&Stub)|null $fixture
     */
    protected function buildTestCase(array $connections, Fixture|null $fixture = null, bool $testConnections = true): FrameworkTestCase
    {
        $app = new Engine(new Loader());
        $fixture ??= $this->createStub(Fixture::class);

        $groups = [];
        $configs = [];
        $namedConnections = [];

        foreach ($connections as $index => $connection) {
            $groups[spl_object_id($connection)] = [
                'connection' => $connection,
                'tables' => ['items', 'items'],
            ];

            $key = $testConnections ? 'test_'.$index : 'default';
            $configs[$key] = [];
            $namedConnections[$key] = $connection;
        }

        $fixture->method('getTablesByConnection')->willReturn($groups);

        $registry = $this->createStub(FixtureRegistry::class);
        $registry->method('use')->willReturn($fixture);

        $app->instance(FixtureRegistry::class, $registry);

        $manager = $this->createStub(ConnectionManager::class);
        $manager->method('getConfig')->willReturn($configs);
        $manager->method('getAliases')->willReturn([]);
        $manager->method('isLoaded')->willReturn(true);
        $manager->method('use')
            ->willReturnCallback(static fn(string $key): Connection => $namedConnections[$key]);

        $app->instance(ConnectionManager::class, $manager);

        $test = new FrameworkTestCase('test');

        Closure::bind(function() use ($app): void {
            $this->app = $app;
            $this->fixtures = ['Items'];
        }, $test, FrameworkTestCase::class)();

        return $test;
    }

    #[Override]
    public static function setUpBeforeClass(): void
    {
        $loader = new Loader();
        $app = new Application($loader);

        Application::setInstance($app);
    }

    #[Override]
    public static function tearDownAfterClass(): void
    {
        Application::getInstance()
            ->use(ErrorHandler::class)
            ->unregister();
    }
}
