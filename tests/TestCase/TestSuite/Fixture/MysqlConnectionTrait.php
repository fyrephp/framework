<?php
declare(strict_types=1);

namespace Tests\TestCase\TestSuite\Fixture;

use Fyre\Core\Config;
use Fyre\Core\Container;
use Fyre\Core\ErrorHandler;
use Fyre\Core\Loader;
use Fyre\DB\Connection;
use Fyre\DB\ConnectionManager;
use Fyre\DB\Handlers\Mysql\MysqlConnection;
use Fyre\ORM\EntityLocator;
use Fyre\ORM\ModelRegistry;
use Fyre\TestSuite\Fixture\Fixture;
use Fyre\TestSuite\Fixture\FixtureRegistry;
use Override;
use Tests\Mock\Application;
use Tests\TestCase\Shared\DatabaseLifecycleTrait;

use function getenv;

trait MysqlConnectionTrait
{
    use DatabaseLifecycleTrait;

    protected Fixture $associatedFixture;

    protected Connection $db;

    protected Fixture $fixture;

    protected FixtureRegistry $fixtureRegistry;

    protected ModelRegistry $modelRegistry;

    protected Fixture $nestedFixture;

    protected static function buildContainer(): Container
    {
        return Application::getInstance();
    }

    protected static function clearSchema(Connection $db): void
    {
        $db->query('DROP TABLE IF EXISTS children');
        $db->query('DROP TABLE IF EXISTS items');
    }

    protected static function createSchema(Connection $db): void
    {
        $db->query(<<<'SQL'
            CREATE TABLE items (
                id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(255) NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci',
                PRIMARY KEY (id)
            ) COLLATE='utf8mb4_unicode_ci' ENGINE=InnoDB
        SQL);

        $db->query(<<<'SQL'
            CREATE TABLE children (
                id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                item_id INT(10) UNSIGNED NULL DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_children_item FOREIGN KEY (item_id) REFERENCES items (id)
            ) COLLATE='utf8mb4_unicode_ci' ENGINE=InnoDB
        SQL);
    }

    #[Override]
    public static function setUpBeforeClass(): void
    {
        $loader = new Loader();
        $app = new Application($loader);

        Application::setInstance($app);

        $app->use(Config::class)
            ->set('App.locale', 'en')
            ->set('Database', [
                'test' => [
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

        $app->use(ConnectionManager::class)->alias('test', 'default');

        static::setUpSchema();
    }

    #[Override]
    public static function tearDownAfterClass(): void
    {
        $app = Application::getInstance();

        try {
            static::tearDownSchema();
        } finally {
            $app->use(ErrorHandler::class)->unregister();
        }
    }

    #[Override]
    protected function setUp(): void
    {
        $app = Application::getInstance();

        $this->modelRegistry = $app->use(ModelRegistry::class);
        $this->modelRegistry->addNamespace('Tests\Mock\Models');

        $this->fixtureRegistry = $app->use(FixtureRegistry::class);
        $this->fixtureRegistry->clearNamespaces();
        $this->fixtureRegistry->addNamespace('Tests\Mock\Fixtures');

        $this->fixture = $this->fixtureRegistry->use('Items');
        $this->associatedFixture = $this->fixtureRegistry->use('ItemsAssociated');
        $this->nestedFixture = $this->fixtureRegistry->use('ItemsNested');

        $app->use(EntityLocator::class)->addNamespace('Tests\Mock\Entities');

        $this->db = $app->use(ConnectionManager::class)->use();

        $this->db->disableForeignKeys();

        try {
            $this->db->truncate('children');
            $this->db->truncate('items');
        } finally {
            $this->db->enableForeignKeys();
        }

        parent::setUp();
    }
}
