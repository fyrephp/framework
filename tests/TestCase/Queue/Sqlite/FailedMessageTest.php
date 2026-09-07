<?php
declare(strict_types=1);

namespace Tests\TestCase\Queue\Sqlite;

use Fyre\Queue\FailedMessage;
use Fyre\Queue\Message;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

use function serialize;
use function time;
use function unserialize;

#[RequiresPhpExtension('pdo_sqlite')]
final class FailedMessageTest extends TestCase
{
    public function testExceptionCodeNumericString(): void
    {
        $connection = new PDO('sqlite::memory:');
        $connection->exec('CREATE TABLE test (id INTEGER PRIMARY KEY)');
        $connection->exec('INSERT INTO test (id) VALUES (1)');

        try {
            $connection->exec('INSERT INTO test (id) VALUES (1)');
            $this->fail('Expected a PDO exception.');
        } catch (PDOException $exception) {
            $failure = new FailedMessage(new Message(), time(), $exception);
        }

        $this->assertSame(
            '23000',
            $failure->getExceptionCode()
        );
        $this->assertSame(
            '23000',
            unserialize(serialize($failure))->getExceptionCode()
        );
    }

    public function testExceptionCodeString(): void
    {
        $connection = new PDO('sqlite::memory:');

        try {
            $connection->exec('SELECT * FROM missing_table');
            $this->fail('Expected a PDO exception.');
        } catch (PDOException $exception) {
            $failure = new FailedMessage(new Message(), time(), $exception);
        }

        $this->assertSame(
            'HY000',
            $failure->getExceptionCode()
        );
        $this->assertSame(
            'HY000',
            unserialize(serialize($failure))->getExceptionCode()
        );
    }
}
