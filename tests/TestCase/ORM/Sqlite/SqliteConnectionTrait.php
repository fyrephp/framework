<?php
declare(strict_types=1);

namespace Tests\TestCase\ORM\Sqlite;

use Fyre\Core\Config;
use Fyre\Core\Container;
use Fyre\Core\Lang;
use Fyre\DB\Connection;
use Fyre\DB\ConnectionManager;
use Fyre\DB\Handlers\Sqlite\SqliteConnection;
use Fyre\DB\Schema\SchemaRegistry;
use Fyre\DB\TypeParser;
use Fyre\Event\EventManager;
use Fyre\ORM\EntityLocator;
use Fyre\ORM\ModelRegistry;
use Fyre\Utility\Inflector;
use Fyre\Utility\Path;
use Tests\TestCase\ORM\Shared\ConnectionTrait;

use function str_replace;

use const ROOT;

trait SqliteConnectionTrait
{
    use ConnectionTrait;

    protected static function buildContainer(): Container
    {
        $container = new Container();
        $container->singleton(TypeParser::class);
        $container->singleton(Config::class);
        $container->singleton(Inflector::class);
        $container->singleton(ConnectionManager::class);
        $container->singleton(SchemaRegistry::class);
        $container->singleton(ModelRegistry::class);
        $container->singleton(EntityLocator::class);
        $container->singleton(EventManager::class);
        $container->singleton(Lang::class);
        $container->use(Config::class)
            ->set('App.locale', 'en')
            ->set('Database', [
                'default' => [
                    'className' => SqliteConnection::class,
                    'database' => 'orm_'.str_replace('\\', '_', static::class),
                    'mode' => 'memory',
                    'cache' => 'shared',
                ],
            ]);

        $container->use(Lang::class)
            ->addPath(Path::join(ROOT, 'lang'));

        return $container;
    }

    protected static function createSchema(Connection $db): void
    {
        $db->query(<<<'SQL'
            CREATE TABLE cascade_parents (
                id INTEGER NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);

        $db->query(<<<'SQL'
            CREATE TABLE cascade_children (
                id INTEGER NOT NULL,
                cascade_parent_id INTEGER NULL,
                PRIMARY KEY (id),
                FOREIGN KEY (cascade_parent_id) REFERENCES cascade_parents (id) ON DELETE RESTRICT
            )
        SQL);

        $db->query(<<<'SQL'
            CREATE TABLE items (
                id INTEGER NOT NULL,
                name VARCHAR(255) NULL DEFAULT NULL,
                PRIMARY KEY (id)
            )
        SQL);

        $db->query(<<<'SQL'
            CREATE TABLE composite_items (
                tenant_id INTEGER NOT NULL,
                id INTEGER NOT NULL,
                name VARCHAR(255) NULL DEFAULT NULL,
                PRIMARY KEY (tenant_id, id)
            )
        SQL);

        $db->query(<<<'SQL'
            CREATE TABLE contains (
                id INTEGER NOT NULL,
                item_id INTEGER NOT NULL,
                contained_item_id INTEGER NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);

        $db->query(<<<'SQL'
            CREATE TABLE others (
                id INTEGER NOT NULL,
                value INTEGER NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);

        $db->query(<<<'SQL'
            CREATE TABLE timestamps (
                id INTEGER NOT NULL,
                created DATETIME NOT NULL,
                modified DATETIME NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);

        $db->query(<<<'SQL'
            CREATE TABLE users (
                id INTEGER NOT NULL,
                name VARCHAR(255) NULL DEFAULT NULL,
                deleted DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (id)
            )
        SQL);

        $db->query(<<<'SQL'
            CREATE TABLE addresses (
                id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                address_1 VARCHAR(255) NULL DEFAULT NULL,
                address_2 VARCHAR(255) NULL DEFAULT NULL,
                suburb VARCHAR(255) NULL DEFAULT NULL,
                state VARCHAR(255) NULL DEFAULT NULL,
                deleted DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (id)
            )
        SQL);

        $db->query(<<<'SQL'
            CREATE TABLE posts (
                id INTEGER NOT NULL,
                user_id INTEGER NULL DEFAULT NULL,
                title VARCHAR(255) NULL DEFAULT NULL,
                content TEXT NULL DEFAULT NULL,
                deleted DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (id)
            )
        SQL);

        $db->query(<<<'SQL'
            CREATE TABLE comments (
                id INTEGER NOT NULL,
                user_id INTEGER NULL DEFAULT NULL,
                post_id INTEGER NULL DEFAULT NULL,
                content TEXT NULL DEFAULT NULL,
                deleted DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (id)
            )
        SQL);

        $db->query(<<<'SQL'
            CREATE TABLE tags (
                id INTEGER NOT NULL,
                tag VARCHAR(255) NULL DEFAULT NULL,
                PRIMARY KEY (id)
            )
        SQL);

        $db->query(<<<'SQL'
            CREATE TABLE posts_tags (
                id INTEGER NOT NULL,
                post_id INTEGER NOT NULL,
                tag_id INTEGER NOT NULL,
                value INTEGER NULL DEFAULT NULL,
                PRIMARY KEY (id)
            )
        SQL);
    }
}
