<?php
declare(strict_types=1);

namespace Fyre\Utility\Promise\Internal;

use Closure;
use Fyre\Utility\Promise\Promise;
use Fyre\Utility\Promise\PromiseInterface;
use Override;
use Throwable;

/**
 * Represents a rejected promise.
 *
 * Note: If the rejection is not handled, the rejection reason is thrown when the
 * RejectedPromise is destroyed.
 *
 * A rejection is considered handled if an onRejected callback is attached via catch/then/finally.
 *
 * @internal
 *
 * @phpstan-consistent-constructor
 */
class RejectedPromise implements PromiseInterface
{
    protected bool $handled = false;

    /**
     * Constructs a RejectedPromise.
     *
     * @param Throwable $reason The rejection reason.
     */
    public function __construct(
        protected Throwable $reason
    ) {}

    /**
     * Destroys the RejectedPromise instance and enforces unhandled rejection checks.
     *
     * @throws Throwable If the rejection reason was not handled.
     */
    public function __destruct()
    {
        if ($this->handled) {
            return;
        }

        throw $this->reason;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function catch(Closure $onRejected): PromiseInterface
    {
        return $this->then(null, $onRejected);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function finally(Closure $onFinally): PromiseInterface
    {
        return $this->then(
            null,
            static fn(Throwable $reason): PromiseInterface => Promise::resolve($onFinally())
                ->then(Promise::reject($reason, ...))
        );
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function then(Closure|null $onFulfilled, Closure|null $onRejected = null): PromiseInterface
    {
        if ($onRejected === null) {
            return $this;
        }

        $this->handled = true;

        try {
            return $onRejected($this->reason) |> Promise::resolve(...);
        } catch (Throwable $e) {
            return new static($e);
        }
    }
}
