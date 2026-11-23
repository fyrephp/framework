<?php
declare(strict_types=1);

namespace Tests\TestCase\DB\Shared;

use Closure;
use Fyre\DB\Connection;
use Fyre\DB\Exceptions\DbException;
use Fyre\DB\Handlers\Postgres\PostgresConnection;
use Fyre\DB\Handlers\Sqlite\SqliteConnection;
use Fyre\Event\Event;
use PHPUnit\Framework\Attributes\DataProvider;

use function str_starts_with;

/**
 * @property Connection $db
 */
trait ConnectionRetryTestTrait
{
    /**
     * @return array<string, array{Closure(Connection): mixed, int}>
     */
    public static function disconnectDuringExecutionProvider(): array
    {
        return [
            'prepared write' => [static fn(Connection $db) => $db->execute('INSERT INTO test (name) VALUES (:name)', ['name' => 'Test']), 1],
            'raw write' => [static fn(Connection $db) => $db->query("INSERT INTO test (name) VALUES ('Test')"), 1],
            'explicit raw retry' => [static fn(Connection $db) => $db->query("INSERT INTO test (name) VALUES ('Test')", retrySafe: true), 2],
            'explicit builder retry' => [static fn(Connection $db) => $db->insert()->into('test')->values([['name' => 'Test']])->execute(retrySafe: true), 2],
            'explicit retry inside transaction' => [static fn(Connection $db) => $db->transactional(
                static fn(Connection $db) => $db->execute('INSERT INTO test (name) VALUES (:name)', ['name' => 'Test'], retrySafe: true)
            ), 1],
        ];
    }

    #[DataProvider('disconnectDuringExecutionProvider')]
    public function testDisconnectDuringExecution(Closure $execute, int $expectedAttempts): void
    {
        $dropTrigger = $this->createDisconnectTrigger();
        $attempts = 0;
        $this->db->getEventManager()->on('Db.query', static function(Event $event, string $sql) use (&$attempts): void {
            if (str_starts_with($sql, 'INSERT')) {
                $attempts++;
            }
        });

        try {
            $execute($this->db);
            $this->fail('Expected the simulated disconnect.');
        } catch (DbException $e) {
            $this->assertStringContainsString('Simulated disconnect', $e->getMessage());
        } finally {
            $this->db->getEventManager()->off('Db.query');
            $dropTrigger();
        }

        $this->assertSame($this->db instanceof SqliteConnection ? 1 : $expectedAttempts, $attempts);
    }

    /**
     * Raises a driver error during INSERT execution and returns the fixture cleanup.
     */
    protected function createDisconnectTrigger(): Closure
    {
        if ($this->db instanceof PostgresConnection) {
            $this->db->query(<<<'SQL'
                CREATE FUNCTION test_disconnect() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                    RAISE EXCEPTION 'Simulated disconnect' USING ERRCODE = '08006';
                END
                $$
            SQL);
            $this->db->query('CREATE TRIGGER test_disconnect BEFORE INSERT ON test FOR EACH ROW EXECUTE FUNCTION test_disconnect()');

            return fn() => $this->db->query('DROP FUNCTION test_disconnect() CASCADE');
        }

        if ($this->db instanceof SqliteConnection) {
            $this->db->query(<<<'SQL'
                CREATE TRIGGER test_disconnect BEFORE INSERT ON test BEGIN
                    SELECT RAISE(ABORT, 'Simulated disconnect');
                END
            SQL);
        } else {
            $this->db->query(<<<'SQL'
                CREATE TRIGGER test_disconnect BEFORE INSERT ON test FOR EACH ROW
                SIGNAL SQLSTATE '08S01' SET MESSAGE_TEXT = 'Simulated disconnect'
            SQL);
        }

        return fn() => $this->db->query('DROP TRIGGER test_disconnect');
    }
}
