<?php
declare(strict_types=1);

namespace Tests\TestCase\Security\RateLimiter;

use Fyre\Cache\CacheManager;
use Fyre\Cache\Handlers\Array\ArrayCacher;
use Fyre\Core\Config;
use Fyre\Core\Container;
use Fyre\Core\Traits\DebugTrait;
use Fyre\Http\ServerRequest;
use Fyre\Security\RateLimiter;
use Fyre\Security\RateLimiter\FixedWindowRateLimiter;
use InvalidArgumentException;
use Override;
use PHPUnit\Framework\TestCase;

use function class_uses;
use function sleep;

final class FixedWindowRateLimiterTest extends TestCase
{
    protected Container $container;

    public function testDebug(): void
    {
        $this->assertContains(
            DebugTrait::class,
            class_uses(RateLimiter::class)
        );
    }

    public function testInvalidCost(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Rate limiter cost must not be negative.');

        $limiter = $this->container->build(FixedWindowRateLimiter::class, [
            'options' => [
                'cost' => static fn(): int => -1,
            ],
        ]);
        $request = $this->container->build(ServerRequest::class);

        $limiter->checkLimit($request);
    }

    public function testInvalidLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Rate limiter limit must be greater than 0.');

        $limiter = $this->container->build(FixedWindowRateLimiter::class, [
            'options' => [
                'limit' => 0,
            ],
        ]);
        $request = $this->container->build(ServerRequest::class);

        $limiter->checkLimit($request);
    }

    public function testInvalidWindow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Rate limiter window must be greater than 0.');

        $limiter = $this->container->build(FixedWindowRateLimiter::class);
        $request = $this->container->build(ServerRequest::class);

        $limiter->checkLimit($request, window: 0);
    }

    public function testLimitAcrossSeconds(): void
    {
        $limiter = $this->container->build(FixedWindowRateLimiter::class, [
            'options' => [
                'limit' => 1,
                'window' => 60,
            ],
        ]);
        $request = $this->container->build(ServerRequest::class);

        $first = $limiter->checkLimit($request);

        $this->assertTrue($first['allowed']);

        sleep(1);

        $second = $limiter->checkLimit($request);

        // If the first request landed in the final second, check the new window instead.
        if ($second['reset'] !== $first['reset']) {
            $first = $second;

            $this->assertTrue($first['allowed']);

            sleep(1);

            $second = $limiter->checkLimit($request);
        }

        $this->assertFalse($second['allowed']);
        $this->assertSame(0, $second['remaining']);
        $this->assertSame($first['reset'], $second['reset']);
        $this->assertSame(0, $second['reset'] % 60);
    }

    public function testLimitResets(): void
    {
        $limiter = $this->container->build(FixedWindowRateLimiter::class, [
            'options' => [
                'limit' => 1,
                'window' => 1,
            ],
        ]);
        $request = $this->container->build(ServerRequest::class);

        $first = $limiter->checkLimit($request);

        $this->assertTrue($first['allowed']);
        $this->assertSame(0, $first['remaining']);

        sleep(1);

        $second = $limiter->checkLimit($request);

        $this->assertTrue($second['allowed']);
        $this->assertSame(0, $second['remaining']);
        $this->assertGreaterThan($first['reset'], $second['reset']);
    }

    #[Override]
    protected function setUp(): void
    {
        $this->container = new Container();
        $this->container->singleton(CacheManager::class);
        $this->container->singleton(Config::class);
        $this->container->use(CacheManager::class)->setConfig('ratelimiter', [
            'className' => ArrayCacher::class,
        ]);
    }
}
