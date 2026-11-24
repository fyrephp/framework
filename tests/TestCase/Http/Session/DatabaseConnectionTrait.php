<?php
declare(strict_types=1);

namespace Tests\TestCase\Http\Session;

use Fyre\DB\Connection;
use Fyre\DB\ConnectionManager;
use Fyre\Http\Session\Handlers\DatabaseSessionHandler;
use Fyre\Http\Session\Session;
use Override;
use Tests\TestCase\Shared\DatabaseLifecycleTrait;

trait DatabaseConnectionTrait
{
    use DatabaseLifecycleTrait;

    protected Connection $db;

    protected DatabaseSessionHandler $handler;

    protected Session $session;

    protected static function clearSchema(Connection $db): void
    {
        $db->query('DROP TABLE IF EXISTS sessions');
    }

    #[Override]
    protected function setUp(): void
    {
        $container = static::buildContainer();

        $this->db = $container->use(ConnectionManager::class)->use();
        $this->db->truncate('sessions');

        $this->session = $container->use(Session::class);
        $handler = $this->session->getHandler();

        $this->assertInstanceOf(DatabaseSessionHandler::class, $handler);

        $this->handler = $handler;

        $this->session->start();

        $this->assertTrue(
            $this->handler->open('sessions', '')
        );
    }

    #[Override]
    protected function tearDown(): void
    {
        try {
            $id = $this->session->id();

            $this->assertTrue(
                $this->handler->destroy($id)
            );

            $this->assertTrue(
                $this->handler->close()
            );
        } finally {
            $this->db->disconnect();
        }
    }
}
