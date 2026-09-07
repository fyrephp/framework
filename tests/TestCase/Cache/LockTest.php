<?php
declare(strict_types=1);

namespace Tests\TestCase\Cache;

use Fyre\Cache\Lock;
use Override;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class LockTest extends TestCase
{
    protected Lock&Stub $lock;

    public function testAcquireAfterRefreshFailure(): void
    {
        $this->lock->method('acquireLock')
            ->willReturn(true);
        $this->lock->method('refreshLock')
            ->willReturn(false);

        $this->lock->acquire();
        $this->lock->refresh();

        $this->assertTrue(
            $this->lock->acquire()
        );
    }

    public function testAcquireWait(): void
    {
        $this->lock->method('acquireLock')
            ->willReturn(false, true);

        $this->assertTrue(
            $this->lock->acquire(1)
        );
    }

    public function testAcquireWaitTimeout(): void
    {
        $this->lock->method('acquireLock')
            ->willReturn(false);

        $this->assertFalse(
            $this->lock->acquire(0.01)
        );
    }

    public function testRefreshFailure(): void
    {
        $this->lock->method('acquireLock')
            ->willReturn(true);
        $this->lock->method('refreshLock')
            ->willReturn(false);

        $this->lock->acquire();

        $this->assertFalse(
            $this->lock->refresh()
        );
    }

    #[Override]
    protected function setUp(): void
    {
        $this->lock = $this->getStubBuilder(Lock::class)
            ->setConstructorArgs(['test'])
            ->onlyMethods(['acquireLock', 'refreshLock', 'releaseLock'])
            ->getStub();
    }
}
