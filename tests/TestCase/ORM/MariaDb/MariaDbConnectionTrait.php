<?php
declare(strict_types=1);

namespace Tests\TestCase\ORM\MariaDb;

use Fyre\Core\Config;
use Fyre\Core\Container;
use Fyre\Core\Lang;
use Fyre\DB\Connection;
use Fyre\DB\ConnectionManager;
use Fyre\DB\Handlers\Mysql\MysqlConnection;
use Fyre\DB\Schema\SchemaRegistry;
use Fyre\DB\TypeParser;
use Fyre\Event\EventManager;
use Fyre\ORM\EntityLocator;
use Fyre\ORM\ModelRegistry;
use Fyre\Utility\Inflector;
use Fyre\Utility\Path;
use Tests\TestCase\ORM\Shared\ConnectionTrait;

use function getenv;

use const ROOT;

trait MariaDbConnectionTrait
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
                    'className' => MysqlConnection::class,
                    'host' => getenv('MARIADB_HOST'),
                    'username' => getenv('MARIADB_USERNAME'),
                    'password' => getenv('MARIADB_PASSWORD'),
                    'database' => getenv('MARIADB_DATABASE'),
                    'port' => getenv('MARIADB_PORT'),
                    'collation' => 'utf8mb4_unicode_ci',
                    'charset' => 'utf8mb4',
                    'compress' => true,
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
                id INT(10) UNSIGNED NOT NULL,
                PRIMARY KEY (id)
            ) COLLATE='utf8mb4_unicode_ci' ENGINE=InnoDB
        SQL);

        $db->query(<<<'SQL'
            CREATE TABLE cascade_children (
                id INT(10) UNSIGNED NOT NULL,
                cascade_parent_id INT(10) UNSIGNED NULL,
                PRIMARY KEY (id),
                FOREIGN KEY (cascade_parent_id) REFERENCES cascade_parents (id) ON DELETE RESTRICT
            ) COLLATE='utf8mb4_unicode_ci' ENGINE=InnoDB
        SQL);

        $db->query(<<<'SQL'
            CREATE TABLE items (
                id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(255) NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci',
                PRIMARY KEY (id)
            ) COLLATE='utf8mb4_unicode_ci' ENGINE=InnoDB
        SQL);

        $db->query(<<<'SQL'
            CREATE TABLE composite_items (
                tenant_id INT(10) UNSIGNED NOT NULL,
                id INT(10) UNSIGNED NOT NULL,
                name VARCHAR(255) NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci',
                PRIMARY KEY (tenant_id, id)
            ) COLLATE='utf8mb4_unicode_ci' ENGINE=InnoDB
        SQL);

        $db->query(<<<'SQL'
            CREATE TABLE contains (
                id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                item_id INT(10) UNSIGNED NOT NULL,
                contained_item_id INT(10) UNSIGNED NOT NULL,
                PRIMARY KEY (id)
            ) COLLATE='utf8mb4_unicode_ci' ENGINE=InnoDB
        SQL);

        $db->query(<<<'SQL'
            CREATE TABLE others (
                id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                value INT(10) UNSIGNED NOT NULL,
                PRIMARY KEY (id)
            ) COLLATE='utf8mb4_unicode_ci' ENGINE=InnoDB
        SQL);

        $db->query(<<<'SQL'
            CREATE TABLE timestamps (
                id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                created DATETIME NOT NULL,
                modified DATETIME NOT NULL,
                PRIMARY KEY (id)
            ) COLLATE='utf8mb4_unicode_ci' ENGINE=InnoDB
        SQL);

        $db->query(<<<'SQL'
            CREATE TABLE users (
                id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(255) NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci',
                deleted DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (id)
            ) COLLATE='utf8mb4_unicode_ci' ENGINE=InnoDB
        SQL);

        $db->query(<<<'SQL'
            CREATE TABLE addresses (
                id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT(10) UNSIGNED NOT NULL,
                address_1 VARCHAR(255) NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci',
                address_2 VARCHAR(255) NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci',
                suburb VARCHAR(255) NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci',
                state VARCHAR(255) NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci',
                deleted DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (id)
            ) COLLATE='utf8mb4_unicode_ci' ENGINE=InnoDB
        SQL);

        $db->query(<<<'SQL'
            CREATE TABLE posts (
                id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT(10) UNSIGNED NULL DEFAULT NULL,
                title VARCHAR(255) NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci',
                content TEXT NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci',
                deleted DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (id)
            ) COLLATE='utf8mb4_unicode_ci' ENGINE=InnoDB
        SQL);

        $db->query(<<<'SQL'
            CREATE TABLE comments (
                id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT(10) UNSIGNED NULL DEFAULT NULL,
                post_id INT(10) UNSIGNED NULL DEFAULT NULL,
                content TEXT NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci',
                deleted DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (id)
            ) COLLATE='utf8mb4_unicode_ci' ENGINE=InnoDB
        SQL);

        $db->query(<<<'SQL'
            CREATE TABLE tags (
                id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                tag VARCHAR(255) NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci',
                PRIMARY KEY (id)
            ) COLLATE='utf8mb4_unicode_ci' ENGINE=InnoDB
        SQL);

        $db->query(<<<'SQL'
            CREATE TABLE posts_tags (
                id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                post_id INT(10) UNSIGNED NOT NULL,
                tag_id INT(10) UNSIGNED NOT NULL,
                value INT(10) UNSIGNED NULL DEFAULT NULL,
                PRIMARY KEY (id)
            ) COLLATE='utf8mb4_unicode_ci' ENGINE=InnoDB
        SQL);
    }
}
