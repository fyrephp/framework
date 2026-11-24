<?php
declare(strict_types=1);

namespace Tests\TestCase\Commands;

use Fyre\Console\Command;
use Fyre\Console\CommandRunner;
use Fyre\Console\Console;
use Fyre\Core\Config;
use Fyre\Core\Container;
use Fyre\Core\Loader;
use Fyre\DB\Connection;
use Fyre\DB\ConnectionManager;
use Fyre\DB\Forge\ForgeRegistry;
use Fyre\DB\Handlers\Mysql\MysqlConnection;
use Fyre\DB\Migration\MigrationRunner;
use Fyre\DB\Schema\Schema;
use Fyre\DB\Schema\SchemaRegistry;
use Fyre\DB\TypeParser;
use Fyre\Event\EventManager;
use Fyre\Utility\Inflector;
use Fyre\Utility\Path;
use Override;
use PHPUnit\Framework\TestCase;

use function array_column;
use function fclose;
use function fopen;
use function getenv;
use function rewind;
use function stream_get_contents;

use const PHP_EOL;
use const ROOT;

final class DbMigrationCommandTest extends TestCase
{
    protected CommandRunner $commandRunner;

    protected ConnectionManager $connectionManager;

    protected Connection $db;

    /**
     * @var resource
     */
    protected $error;

    /**
     * @var resource
     */
    protected $input;

    protected MigrationRunner $migrationRunner;

    /**
     * @var resource
     */
    protected $output;

    protected Schema $schema;

    public function testDbMigrate(): void
    {
        $this->assertSame(
            Command::CODE_SUCCESS,
            $this->commandRunner->run('db:migrate', [
                'db' => ConnectionManager::DEFAULT,
            ])
        );

        $this->schema->clear();

        $this->assertTrue(
            $this->schema->hasTable('test1')
        );
        $this->assertTrue(
            $this->schema->hasTable('test2')
        );
        $this->assertTrue(
            $this->schema->hasTable('test3')
        );

        rewind($this->output);

        $this->assertSame(
            "\033[0;32mApplied 3 migration(s).\033[0m".PHP_EOL,
            stream_get_contents($this->output)
        );
    }

    public function testDbMigrateDryRun(): void
    {
        $this->assertSame(
            Command::CODE_SUCCESS,
            $this->commandRunner->run('db:migrate', [
                'dryRun' => true,
            ])
        );

        rewind($this->output);

        $this->assertSame(
            '+-----------+--------+'.PHP_EOL.
            '| Migration | Action |'.PHP_EOL.
            '+-----------+--------+'.PHP_EOL.
            '| 1_Test1   | up     |'.PHP_EOL.
            '| 2_Test2   | up     |'.PHP_EOL.
            '| 3_Test3   | up     |'.PHP_EOL.
            '+-----------+--------+'.PHP_EOL,
            stream_get_contents($this->output)
        );

        $this->assertFalse(
            $this->schema->hasTable('fyre__migrations')
        );
        $this->assertFalse(
            $this->schema->hasTable('fyre__locks')
        );
        $this->assertFalse(
            $this->schema->hasTable('test1')
        );
        $this->assertFalse(
            $this->schema->hasTable('test2')
        );
        $this->assertFalse(
            $this->schema->hasTable('test3')
        );
    }

    public function testDbMigrateDryRunEmpty(): void
    {
        $this->migrationRunner->migrate();

        $this->assertSame(
            Command::CODE_SUCCESS,
            $this->commandRunner->run('db:migrate', [
                'dryRun' => true,
            ])
        );

        rewind($this->output);

        $this->assertSame(
            "\033[0;34mNo pending migrations.\033[0m".PHP_EOL,
            stream_get_contents($this->output)
        );
    }

    public function testDbMigrateEmpty(): void
    {
        $this->migrationRunner->migrate();

        $this->assertSame(
            Command::CODE_SUCCESS,
            $this->commandRunner->run('db:migrate', [
                'db' => ConnectionManager::DEFAULT,
            ])
        );

        rewind($this->output);

        $this->assertSame(
            "\033[0;34mNo pending migrations.\033[0m".PHP_EOL,
            stream_get_contents($this->output)
        );
    }

    public function testDbRollback(): void
    {
        $this->migrationRunner->migrate();

        $this->assertSame(
            Command::CODE_SUCCESS,
            $this->commandRunner->run('db:rollback', [
                'db' => ConnectionManager::DEFAULT,
            ])
        );

        $this->schema->clear();

        $this->assertFalse(
            $this->schema->hasTable('test1')
        );
        $this->assertFalse(
            $this->schema->hasTable('test2')
        );
        $this->assertFalse(
            $this->schema->hasTable('test3')
        );

        $this->assertArraysAreIdentical(
            [],
            $this->migrationRunner->getHistory()->all()
        );

        rewind($this->output);

        $this->assertSame(
            "\033[0;32mRolled back 3 migration(s).\033[0m".PHP_EOL,
            stream_get_contents($this->output)
        );
    }

    public function testDbRollbackBatches(): void
    {
        $this->migrationRunner->migrate();
        $this->migrationRunner->rollback(steps: 1);
        $this->migrationRunner->migrate();

        $this->assertSame(
            Command::CODE_SUCCESS,
            $this->commandRunner->run('db:rollback', [
                'db' => ConnectionManager::DEFAULT,
                'batches' => '1',
            ])
        );

        $this->schema->clear();

        $this->assertTrue(
            $this->schema->hasTable('test1')
        );
        $this->assertTrue(
            $this->schema->hasTable('test2')
        );
        $this->assertFalse(
            $this->schema->hasTable('test3')
        );

        $this->assertArraysAreIdentical(
            [
                '2_Test2',
                '1_Test1',
            ],
            array_column($this->migrationRunner->getHistory()->all(), 'migration')
        );

        rewind($this->output);

        $this->assertSame(
            "\033[0;32mRolled back 1 migration(s).\033[0m".PHP_EOL,
            stream_get_contents($this->output)
        );
    }

    public function testDbRollbackDryRun(): void
    {
        $this->migrationRunner->migrate();

        $this->assertSame(
            Command::CODE_SUCCESS,
            $this->commandRunner->run('db:rollback', [
                'dryRun' => true,
            ])
        );

        rewind($this->output);

        $this->assertSame(
            '+-----------+--------+'.PHP_EOL.
            '| Migration | Action |'.PHP_EOL.
            '+-----------+--------+'.PHP_EOL.
            '| 3_Test3   | down   |'.PHP_EOL.
            '| 2_Test2   | down   |'.PHP_EOL.
            '| 1_Test1   | down   |'.PHP_EOL.
            '+-----------+--------+'.PHP_EOL,
            stream_get_contents($this->output)
        );

        $this->schema->clear();

        $this->assertTrue(
            $this->schema->hasTable('test1')
        );
        $this->assertTrue(
            $this->schema->hasTable('test2')
        );
        $this->assertTrue(
            $this->schema->hasTable('test3')
        );

        $this->assertArraysAreIdentical(
            [
                '3_Test3',
                '2_Test2',
                '1_Test1',
            ],
            array_column($this->migrationRunner->getHistory()->all(), 'migration')
        );
    }

    public function testDbRollbackDryRunEmpty(): void
    {
        $this->assertSame(
            Command::CODE_SUCCESS,
            $this->commandRunner->run('db:rollback', [
                'dryRun' => true,
            ])
        );

        rewind($this->output);

        $this->assertSame(
            "\033[0;34mNo migrations to roll back.\033[0m".PHP_EOL,
            stream_get_contents($this->output)
        );
    }

    public function testDbRollbackEmpty(): void
    {
        $this->assertSame(
            Command::CODE_SUCCESS,
            $this->commandRunner->run('db:rollback', [
                'db' => ConnectionManager::DEFAULT,
            ])
        );

        rewind($this->output);

        $this->assertSame(
            "\033[0;34mNo migrations to roll back.\033[0m".PHP_EOL,
            stream_get_contents($this->output)
        );
    }

    public function testDbRollbackSteps(): void
    {
        $this->migrationRunner->migrate();

        $this->assertSame(
            Command::CODE_SUCCESS,
            $this->commandRunner->run('db:rollback', [
                'db' => ConnectionManager::DEFAULT,
                'steps' => '2',
            ])
        );

        $this->schema->clear();

        $this->assertTrue(
            $this->schema->hasTable('test1')
        );
        $this->assertFalse(
            $this->schema->hasTable('test2')
        );
        $this->assertFalse(
            $this->schema->hasTable('test3')
        );

        $this->assertArraysAreIdentical(
            [
                '1_Test1',
            ],
            array_column($this->migrationRunner->getHistory()->all(), 'migration')
        );

        rewind($this->output);

        $this->assertSame(
            "\033[0;32mRolled back 2 migration(s).\033[0m".PHP_EOL,
            stream_get_contents($this->output)
        );
    }

    public function testDbStatus(): void
    {
        $history = $this->migrationRunner->getHistory();
        $history->add('0_Missing', 1);
        $history->add('1_Test1', 2);

        $this->assertSame(
            Command::CODE_SUCCESS,
            $this->commandRunner->run('db:status', [
                'db' => ConnectionManager::DEFAULT,
            ])
        );

        rewind($this->output);

        $this->assertSame(
            '+-----------+---------+-------+'.PHP_EOL.
            '| Migration | Status  | Batch |'.PHP_EOL.
            '+-----------+---------+-------+'.PHP_EOL.
            '| 0_Missing | missing | 1     |'.PHP_EOL.
            '| 1_Test1   | up      | 2     |'.PHP_EOL.
            '| 2_Test2   | down    | -     |'.PHP_EOL.
            '| 3_Test3   | down    | -     |'.PHP_EOL.
            '+-----------+---------+-------+'.PHP_EOL,
            stream_get_contents($this->output)
        );

        rewind($this->error);

        $this->assertSame(
            '',
            stream_get_contents($this->error)
        );

        $this->assertFalse(
            $this->schema->hasTable('fyre__locks')
        );
    }

    public function testDbStatusDb(): void
    {
        $this->assertSame(
            Command::CODE_SUCCESS,
            $this->commandRunner->run('db:status', [
                'db' => 'other',
            ])
        );

        $this->assertSame(
            $this->connectionManager->use('other'),
            $this->migrationRunner->getConnection()
        );
    }

    public function testDbStatusEmpty(): void
    {
        $this->migrationRunner->clear();

        $this->assertSame(
            Command::CODE_SUCCESS,
            $this->commandRunner->run('db:status', [
                'db' => ConnectionManager::DEFAULT,
            ])
        );

        rewind($this->output);

        $this->assertSame(
            "\033[0;34mNo migrations found.\033[0m".PHP_EOL,
            stream_get_contents($this->output)
        );
    }

    #[Override]
    protected function setUp(): void
    {
        $container = new Container();
        $container->singleton(Loader::class);
        $container->singleton(Inflector::class);
        $container->singleton(Config::class);
        $container->singleton(EventManager::class);
        $container->singleton(CommandRunner::class);
        $container->singleton(TypeParser::class);
        $container->singleton(ConnectionManager::class);
        $container->singleton(ForgeRegistry::class);
        $container->singleton(MigrationRunner::class);
        $container->singleton(SchemaRegistry::class);
        $database = [
            'className' => MysqlConnection::class,
            'host' => getenv('MYSQL_HOST'),
            'username' => getenv('MYSQL_USERNAME'),
            'password' => getenv('MYSQL_PASSWORD'),
            'database' => getenv('MYSQL_DATABASE'),
            'port' => getenv('MYSQL_PORT'),
            'collation' => 'utf8mb4_unicode_ci',
            'charset' => 'utf8mb4',
            'compress' => true,
        ];

        $container->use(Config::class)->set('Database', [
            'default' => $database,
            'other' => $database,
        ]);

        $this->connectionManager = $container->use(ConnectionManager::class);
        $this->migrationRunner = $container->use(MigrationRunner::class);

        $this->db = $this->connectionManager->use();
        $this->schema = $container->use(SchemaRegistry::class)->use($this->db);

        $container->use(Loader::class)->addNamespaces([
            'Fyre\Commands\\' => Path::join(ROOT, 'src/Commands'),
            'Tests\Mock\Migrations' => 'tests/Mock/Migrations',
        ]);

        $this->migrationRunner->addNamespace('\Tests\Mock\Migrations');

        $input = fopen('php://memory', 'r+b');
        $output = fopen('php://memory', 'r+b');
        $error = fopen('php://memory', 'r+b');

        $this->assertIsResource($input);
        $this->assertIsResource($output);
        $this->assertIsResource($error);

        $this->input = $input;
        $this->output = $output;
        $this->error = $error;

        $container->instance(
            Console::class,
            new Console($this->input, $this->output, $this->error)
        );

        $this->commandRunner = $container->use(CommandRunner::class);
        $this->commandRunner->addNamespace('Fyre\Commands');
    }

    #[Override]
    protected function tearDown(): void
    {
        try {
            $this->db->query('DROP TABLE IF EXISTS fyre__locks');
            $this->db->query('DROP TABLE IF EXISTS fyre__migrations');
            $this->db->query('DROP TABLE IF EXISTS test1');
            $this->db->query('DROP TABLE IF EXISTS test2');
            $this->db->query('DROP TABLE IF EXISTS test3');
        } finally {
            $this->db->disconnect();

            if ($this->connectionManager->isLoaded('other')) {
                $this->connectionManager->use('other')->disconnect();
            }

            fclose($this->input);
            fclose($this->output);
            fclose($this->error);
        }
    }
}
