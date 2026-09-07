<?php
declare(strict_types=1);

namespace Tests\TestCase\ORM\Shared;

use Fyre\Core\Container;
use Fyre\DB\Connection;
use Fyre\DB\ConnectionManager;
use Fyre\DB\Schema\SchemaRegistry;
use Fyre\ORM\EntityLocator;
use Fyre\ORM\ModelRegistry;
use Override;
use Tests\TestCase\Shared\DatabaseLifecycleTrait;

trait ConnectionTrait
{
    use DatabaseLifecycleTrait;

    protected const TABLES = [
        'cascade_children',
        'cascade_parents',
        'contains',
        'composite_items',
        'items',
        'others',
        'timestamps',
        'users',
        'addresses',
        'posts',
        'comments',
        'tags',
        'posts_tags',
    ];

    protected Container $container;

    protected Connection $db;

    protected ModelRegistry $modelRegistry;

    protected SchemaRegistry $schemaRegistry;

    protected static function clearSchema(Connection $db): void
    {
        foreach (static::TABLES as $table) {
            $db->query('DROP TABLE IF EXISTS '.$table);
        }
    }

    #[Override]
    protected function setUp(): void
    {
        $this->container = static::buildContainer();
        $this->schemaRegistry = $this->container->use(SchemaRegistry::class);
        $this->modelRegistry = $this->container->use(ModelRegistry::class);

        $this->modelRegistry->addNamespace('Tests\Mock\Models\ORM');

        $this->container->use(EntityLocator::class)->addNamespace('Tests\Mock\Entities');

        $this->db = $this->container->use(ConnectionManager::class)->use();

        foreach (static::TABLES as $table) {
            // Referenced tables cannot be truncated on all handlers.
            if ($table === 'cascade_children' || $table === 'cascade_parents') {
                $this->db->delete()->from($table)->execute();

                continue;
            }

            $this->db->truncate($table);
        }
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->db->disconnect();
    }
}
