<?php
declare(strict_types=1);

namespace Tests\TestCase\DB\Migration\Sqlite;

use Fyre\Core\Config;
use Fyre\Core\Container;
use Fyre\Core\Loader;
use Fyre\DB\Connection;
use Fyre\DB\ConnectionManager;
use Fyre\DB\Forge\ForgeRegistry;
use Fyre\DB\Handlers\Sqlite\SqliteConnection;
use Fyre\DB\Migration\MigrationRunner;
use Fyre\DB\Schema\Schema;
use Fyre\DB\Schema\SchemaRegistry;
use Fyre\DB\TypeParser;
use Override;

trait SqliteConnectionTrait
{
    protected Connection $db;

    protected ForgeRegistry $forgeRegistry;

    protected MigrationRunner $migrationRunner;

    protected Schema $schema;

    protected TypeParser $typeParser;

    #[Override]
    protected function setUp(): void
    {
        $container = new Container();
        $container->singleton(Loader::class);
        $container->singleton(Config::class);
        $container->singleton(TypeParser::class);
        $container->singleton(ConnectionManager::class);
        $container->singleton(SchemaRegistry::class);
        $container->singleton(ForgeRegistry::class);
        $container->use(Config::class)->set('Database', [
            'default' => [
                'className' => SqliteConnection::class,
            ],
        ]);

        $this->typeParser = $container->use(TypeParser::class);
        $this->forgeRegistry = $container->use(ForgeRegistry::class);
        $this->migrationRunner = $container->use(MigrationRunner::class);

        $this->db = $container->use(ConnectionManager::class)->use();
        $this->schema = $container->use(SchemaRegistry::class)->use($this->db);

        $container->use(Loader::class)->addNamespaces([
            'Tests\Mock\Migrations' => 'tests/Mock/Migrations',
        ]);

        $this->migrationRunner->addNamespace('\Tests\Mock\Migrations');
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
        }
    }
}
