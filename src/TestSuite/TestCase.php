<?php
declare(strict_types=1);

namespace Fyre\TestSuite;

use Closure;
use Fyre\Core\Engine;
use Fyre\DB\Connection;
use Fyre\DB\ConnectionManager;
use Fyre\TestSuite\Fixture\FixtureRegistry;
use Override;
use Throwable;

use function array_reverse;
use function array_unique;
use function array_values;
use function assert;
use function spl_object_id;

/**
 * Base PHPUnit test case for the framework test suite.
 *
 * Loads configured fixtures before each test and truncates them after each test, with
 * foreign key checks temporarily disabled while fixtures are applied.
 */
class TestCase extends \PHPUnit\Framework\TestCase
{
    protected Engine $app;

    /**
     * @var string[]
     */
    protected array $fixtures = [];

    /**
     * Skip the test if the condition is true.
     *
     * @param bool $shouldSkip Whether the test should be skipped.
     * @param string $message The message to display if skipped.
     * @return bool Whether the test was skipped.
     */
    public function skipIf(bool $shouldSkip, string $message = ''): bool
    {
        if ($shouldSkip) {
            $this->markTestSkipped($message);
        }

        return $shouldSkip;
    }

    /**
     * Skip the test unless the condition is true.
     *
     * @param bool $shouldNotSkip Whether the test should not be skipped.
     * @param string $message The message to display if skipped.
     * @return bool Whether the test was not skipped.
     */
    public function skipUnless(bool $shouldNotSkip, string $message = ''): bool
    {
        if (!$shouldNotSkip) {
            $this->markTestSkipped($message);
        }

        return $shouldNotSkip;
    }

    /**
     * Collects fixture tables and validates every connection before changing data.
     *
     * @return array<int, array{connection: Connection, tables: string[]}> The fixture tables.
     */
    protected function getFixtureTables(): array
    {
        $connectionManager = $this->app->use(ConnectionManager::class);
        $fixtureRegistry = $this->app->use(FixtureRegistry::class);
        $groups = [];

        foreach ($this->fixtures as $fixture) {
            foreach ($fixtureRegistry->use($fixture)->getTablesByConnection() as $group) {
                ConnectionHelper::assertTestConnection($connectionManager, $group['connection']);
                $key = spl_object_id($group['connection']);
                $groups[$key] ??= ['connection' => $group['connection'], 'tables' => []];
                $groups[$key]['tables'] = array_values(array_unique([
                    ...$groups[$key]['tables'],
                    ...$group['tables'],
                ]));
            }
        }

        return $groups;
    }

    /**
     * Set up the fixtures.
     */
    protected function setupFixtures(): void
    {
        if ($this->fixtures === []) {
            return;
        }

        $groups = $this->getFixtureTables();
        $fixtureRegistry = $this->app->use(FixtureRegistry::class);

        $this->withFixtureConnections($groups, function() use ($fixtureRegistry): void {
            foreach ($this->fixtures as $fixture) {
                $fixtureRegistry->use($fixture)->run();
            }
        });
    }

    /**
     * Tear down the fixtures.
     */
    protected function teardownFixtures(): void
    {
        if ($this->fixtures === []) {
            return;
        }

        $groups = $this->getFixtureTables();

        $this->withFixtureConnections($groups, static function() use ($groups): void {
            foreach ($groups as $group) {
                foreach ($group['tables'] as $table) {
                    $group['connection']->truncate($table);
                }
            }
        });
    }

    /**
     * Disables foreign keys on each fixture connection while running a callback.
     *
     * @param array<int, array{connection: Connection, tables: string[]}> $groups The fixture tables.
     * @param Closure(): void $callback The callback.
     */
    protected function withFixtureConnections(array $groups, Closure $callback): void
    {
        $connections = [];

        try {
            foreach ($groups as $group) {
                $connection = $group['connection'];
                $connection->disableForeignKeys();
                $connections[] = $connection;
            }

            $callback();
        } finally {
            $exception = null;

            foreach (array_reverse($connections) as $connection) {
                try {
                    $connection->enableForeignKeys();
                } catch (Throwable $e) {
                    $exception = $e;
                }
            }

            if ($exception !== null) {
                throw $exception;
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    protected function setUp(): void
    {
        $app = Engine::getInstance();

        assert($app instanceof Engine);

        $this->app = $app;
        $this->app->clearScoped();

        $this->setupFixtures();
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    protected function tearDown(): void
    {
        $this->teardownFixtures();
    }
}
