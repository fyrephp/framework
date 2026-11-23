<?php
declare(strict_types=1);

namespace Tests\TestCase\DB\Sqlite;

use BadMethodCallException;
use Fyre\DB\Exceptions\DbException;
use Fyre\DB\Handlers\Sqlite\SqliteConnection;
use Fyre\Event\Event;
use PHPUnit\Framework\TestCase;
use Tests\TestCase\DB\Shared\ConnectionRetryTestTrait;
use Tests\TestCase\DB\Shared\LockTestTrait;

use function file_get_contents;
use function preg_quote;

final class ConnectionTest extends TestCase
{
    use ConnectionRetryTestTrait;
    use LockTestTrait;
    use QuoteIdentifierTestTrait;
    use SqliteConnectionTrait;

    public function testCharset(): void
    {
        $this->assertSame(
            'UTF-8',
            $this->db->getCharset()
        );
    }

    public function testDebug(): void
    {
        $data = $this->db->__debugInfo();

        $this->assertArraysAreIdentical(
            [
                '[class]' => SqliteConnection::class,
                'affectedRows' => null,
                'afterCommitCallbacks' => [],
                'afterRollbackCallbacks' => [],
                'config' => [
                    'log' => false,
                    'database' => '[*****]',
                    'mask' => 420,
                    'cache' => 'shared',
                    'mode' => 'memory',
                    'persist' => false,
                    'flags' => [],
                    'className' => SqliteConnection::class,
                ],
                'container' => '[Fyre\Core\Container]',
                'eventManager' => '[Fyre\Event\EventManager]',
                'inTransaction' => false,
                'logManager' => '[Fyre\Log\LogManager]',
                'logQueries' => false,
                'pdo' => '[Pdo\Sqlite]',
                'savePointLevel' => 0,
                'useSavePoints' => true,
                'version' => null,
            ],
            $data
        );
    }

    public function testEventQuery(): void
    {
        $ran = false;
        $this->db->getEventManager()->on('Db.query', function(Event $event, string $sql, array|null $params = null) use (&$ran): void {
            $ran = true;

            $this->assertSame('SELECT 1 FROM test', $sql);
            $this->assertNull($params);
        });

        $this->db->query('SELECT 1 FROM test');

        $this->assertTrue($ran);

        $this->db->getEventManager()->off('Db.query');
    }

    public function testEventQueryParams(): void
    {
        $ran = false;
        $this->db->getEventManager()->on('Db.query', function(Event $event, string $sql, array|null $params = null) use (&$ran): void {
            $ran = true;

            $this->assertSame('SELECT ? FROM test', $sql);
            $queryParams = $params;

            $this->assertIsArray($queryParams);
            $this->assertArraysAreIdentical([1], $queryParams);
        });

        $this->db->execute('SELECT ? FROM test', [1]);

        $this->assertTrue($ran);

        $this->db->getEventManager()->off('Db.query');
    }

    public function testFailedQuery(): void
    {
        $this->expectException(DbException::class);
        $this->expectExceptionMessageIsOrContains('Database error: ');

        $this->db->query('INVALID');
    }

    public function testForeignKeys(): void
    {
        $this->assertSame(
            $this->db,
            $this->db->disableForeignKeys()
        );

        $row = $this->db->query('PRAGMA foreign_keys')
            ->first();

        $this->assertIsArray($row);

        $this->assertSame(
            0,
            $row['foreign_keys']
        );

        $this->assertSame(
            $this->db,
            $this->db->enableForeignKeys()
        );

        $row = $this->db->query('PRAGMA foreign_keys')
            ->first();

        $this->assertIsArray($row);

        $this->assertSame(
            1,
            $row['foreign_keys']
        );
    }

    public function testHintUnsupported(): void
    {
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessageIs('Optimizer hints are not supported by this connection.');

        $this->db->select()
            ->hint('TEST()');
    }

    public function testQueryLogging(): void
    {
        $this->assertSame(
            $this->db,
            $this->db->enableQueryLogging()
        );

        $this->db->select([
            'test.id',
            'test.name',
        ])
            ->from('test')
            ->where([
                'test.name' => 'test',
            ])
            ->execute();

        $this->assertSame(
            $this->db,
            $this->db->disableQueryLogging()
        );

        $sql = 'SELECT "test"."id", "test"."name" FROM "test" WHERE "test"."name" = \'test\'';

        $this->assertMatchesRegularExpression(
            '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} \[DEBUG\] '.preg_quote($sql, '/').'\R\z/',
            file_get_contents('log/queries-cli.log') ?: ''
        );
    }

    public function testRawQueryLogging(): void
    {
        $this->db->enableQueryLogging();

        $this->db->rawQuery('SELECT 1');

        $this->db->disableQueryLogging();

        $this->assertMatchesRegularExpression(
            '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} \[DEBUG\] SELECT 1\R\z/',
            file_get_contents('log/queries-cli.log') ?: ''
        );
    }

    public function testTruncate(): void
    {
        $this->db->insert()
            ->into('test')
            ->values([
                [
                    'name' => 'Test',
                ],
            ])
            ->execute();

        $row = $this->db->select()
            ->from('test')
            ->execute()
            ->first();

        $this->assertIsArray($row);
        $this->assertArraysAreIdentical(
            [
                'id' => 1,
                'name' => 'Test',
            ],
            $row
        );

        $this->assertSame(
            $this->db,
            $this->db->truncate('test')
        );

        $this->assertArraysAreIdentical(
            [],
            $this->db->select()
                ->from('test')
                ->execute()
                ->all()
        );

        $this->db->insert()
            ->into('test')
            ->values([
                [
                    'name' => 'Test 2',
                ],
            ])
            ->execute();

        $row = $this->db->select()
            ->from('test')
            ->execute()
            ->first();

        $this->assertIsArray($row);
        $this->assertArraysAreIdentical(
            [
                'id' => 1,
                'name' => 'Test 2',
            ],
            $row
        );
    }

    public function testVersion(): void
    {
        $this->assertMatchesRegularExpression(
            '/\A\d+\.\d+.*/',
            $this->db->version()
        );
    }
}
