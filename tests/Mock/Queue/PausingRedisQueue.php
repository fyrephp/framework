<?php
declare(strict_types=1);

namespace Tests\Mock\Queue;

use Fiber;
use Fyre\Queue\FailedMessage;
use Fyre\Queue\Handlers\RedisQueue;
use Override;

class PausingRedisQueue extends RedisQueue
{
    /**
     * Pauses the retry after Redis has returned the failure record.
     */
    #[Override]
    protected static function parseFailure(string $failure): FailedMessage|null
    {
        Fiber::suspend();

        return parent::parseFailure($failure);
    }
}
