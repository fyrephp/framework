<?php
declare(strict_types=1);

namespace Tests\TestCase\DB\Forge\Mysql;

use Fyre\Core\Container;
use Fyre\DB\ConnectionManager;
use Fyre\DB\Forge\ForgeRegistry;
use Fyre\DB\Forge\Handlers\Mysql\MysqlForge;
use Fyre\DB\Forge\QueryGenerator;
use Fyre\DB\Handlers\Mysql\MysqlConnection;
use Fyre\DB\Schema\Schema;
use Fyre\DB\Schema\SchemaRegistry;
use Fyre\DB\TypeParser;
use Override;

use function getenv;

trait MysqlConnectionTrait
{
    protected MysqlConnection $db;

    protected MysqlForge $forge;

    protected QueryGenerator $generator;

    protected Schema $schema;

    #[Override]
    protected function setUp(): void
    {
        $container = new Container();
        $container->singleton(TypeParser::class);
        $container->singleton(SchemaRegistry::class);

        $db = $container->use(ConnectionManager::class)->build([
            'className' => MysqlConnection::class,
            'host' => getenv('MYSQL_HOST'),
            'username' => getenv('MYSQL_USERNAME'),
            'password' => getenv('MYSQL_PASSWORD'),
            'database' => getenv('MYSQL_DATABASE'),
            'port' => getenv('MYSQL_PORT'),
            'collation' => 'utf8mb4_unicode_ci',
            'charset' => 'utf8mb4',
            'compress' => true,
            'persist' => true,
        ]);

        $this->assertInstanceOf(MysqlConnection::class, $db);

        $this->db = $db;
        $this->schema = $container->use(SchemaRegistry::class)->use($this->db);

        $forge = $container->use(ForgeRegistry::class)->use($this->db);

        $this->assertInstanceOf(MysqlForge::class, $forge);

        $this->forge = $forge;
        $this->generator = $this->forge->generator();
    }

    #[Override]
    protected function tearDown(): void
    {
        try {
            $this->db->query('DROP TABLE IF EXISTS test');
            $this->db->query('DROP TABLE IF EXISTS test_values');
            $this->db->query('DROP TABLE IF EXISTS other');
            $this->db->query('DROP SCHEMA IF EXISTS other');
        } finally {
            $this->db->disconnect();
        }
    }
}
