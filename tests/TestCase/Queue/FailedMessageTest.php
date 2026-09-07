<?php
declare(strict_types=1);

namespace Tests\TestCase\Queue;

use Fyre\Queue\FailedMessage;
use Fyre\Queue\Message;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function serialize;
use function time;
use function unserialize;

final class FailedMessageTest extends TestCase
{
    public function testExceptionCodeInteger(): void
    {
        $failure = new FailedMessage(new Message(), time(), new RuntimeException('Test failure.', 5));

        $this->assertSame(
            5,
            $failure->getExceptionCode()
        );
        $this->assertSame(
            5,
            unserialize(serialize($failure))->getExceptionCode()
        );
    }

    public function testExceptionCodeNull(): void
    {
        $failure = new FailedMessage(new Message(), time());

        $this->assertNull(
            $failure->getExceptionCode()
        );
        $this->assertNull(
            unserialize(serialize($failure))->getExceptionCode()
        );
    }
}
