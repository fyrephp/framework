<?php
declare(strict_types=1);

namespace Fyre\Security\Middleware;

use Fyre\Core\Container;
use Fyre\Core\Traits\DebugTrait;
use Fyre\Http\Exceptions\TooManyRequestsException;
use Fyre\Security\RateLimiter;
use Fyre\Security\RateLimiter\FixedWindowRateLimiter;
use Fyre\Security\RateLimiter\SlidingWindowRateLimiter;
use Fyre\Security\RateLimiter\TokenBucketRateLimiter;
use InvalidArgumentException;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function is_string;
use function is_subclass_of;
use function max;
use function sprintf;
use function time;

/**
 * HTTP middleware that enforces request rate limits.
 */
class RateLimiterMiddleware implements MiddlewareInterface
{
    use DebugTrait;

    protected RateLimiter $limiter;

    /**
     * Constructs a RateLimiterMiddleware.
     *
     * @param Container $container The Container.
     * @param array<string, mixed> $options The RateLimiter options.
     *
     * @throws InvalidArgumentException If the rate limiter is not valid.
     */
    public function __construct(Container $container, array $options = [])
    {
        $options['strategy'] ??= null;
        $options['className'] ??= match ($options['strategy']) {
            'fixedWindow' => FixedWindowRateLimiter::class,
            'tokenBucket' => TokenBucketRateLimiter::class,
            default => SlidingWindowRateLimiter::class
        };

        if (
            !is_string($options['className']) ||
            !is_subclass_of($options['className'], RateLimiter::class)
        ) {
            throw new InvalidArgumentException(sprintf(
                'Rate limiter `%s` must extend `%s`.',
                $options['className'],
                RateLimiter::class
            ));
        }

        /** @var class-string<RateLimiter> $className */
        $className = $options['className'];

        $this->limiter = $container->build($className, ['options' => $options]);
    }

    /**
     * {@inheritDoc}
     *
     * Note: The limiter may add rate limit headers to successful responses. When the limit
     * is exceeded, a {@see TooManyRequestsException} is thrown with a `Retry-After` header.
     *
     * @param string|null $limit The limit override.
     * @param string|null $window The window override.
     * @param string|null $cost The cost override.
     *
     * @throws TooManyRequestsException If the rate limit is exceeded.
     */
    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler, string|null $limit = null, string|null $window = null, string|null $cost = null): ResponseInterface
    {
        if ($this->limiter->shouldSkip($request)) {
            return $handler->handle($request);
        }

        $data = $this->limiter->checkLimit(
            $request,
            $limit !== null ? (int) $limit : null,
            $window !== null ? (int) $window : null,
            $cost !== null ? (int) $cost : null
        );

        if (!$data['allowed']) {
            throw new TooManyRequestsException($this->limiter->getMessage(), headers: [
                'Retry-After' => (string) max(1, $data['reset'] - time()),
            ]);
        }

        return $handler->handle($request) |> $this->limiter->addHeaders(?, $data);
    }
}
