<?php
declare(strict_types=1);

namespace Tests\TestCase\DB\Schema\Shared;

use Fyre\Cache\CacheManager;
use Fyre\Cache\Cacher;
use Fyre\DB\Connection;
use Fyre\DB\ConnectionManager;
use Fyre\DB\Schema\Schema;
use Fyre\DB\Schema\SchemaRegistry;
use Override;
use Tests\TestCase\Shared\DatabaseLifecycleTrait;

use function rmdir;

trait ConnectionTrait
{
    use DatabaseLifecycleTrait;

    protected Cacher $cacher;

    protected Connection $db;

    protected Schema $schema;

    protected static function clearSchema(Connection $db): void
    {
        $db->query('DROP TABLE IF EXISTS test_values');
        $db->query('DROP TABLE IF EXISTS test');

        @rmdir('tmp');
    }

    #[Override]
    protected function setUp(): void
    {
        $container = static::buildContainer();

        $this->db = $container->use(ConnectionManager::class)->use();
        $this->cacher = $container->use(CacheManager::class)->use('_schema');
        $this->cacher->clear();
        $this->schema = $container->use(SchemaRegistry::class)->use($this->db);
    }

    #[Override]
    protected function tearDown(): void
    {
        try {
            $this->cacher->clear();
        } finally {
            $this->db->disconnect();
        }

        @rmdir('tmp');
    }
}
