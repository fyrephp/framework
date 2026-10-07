<?php
declare(strict_types=1);

namespace Fyre\TestSuite\Queue\Handlers;

use Fyre\Queue\Message;
use Fyre\Queue\Queue;
use Override;
use Throwable;

use function array_filter;
use function array_map;
use function array_unique;
use function array_values;

/**
 * Captures dispatched jobs without connecting to a broker or executing them.
 */
class TestQueue extends Queue
{
    /**
     * @var Message[]
     */
    protected static array $messages = [];

    /**
     * Clears captured messages from every queue configuration.
     */
    public static function clearMessages(): void
    {
        static::$messages = [];
    }

    /**
     * Returns captured messages in dispatch order.
     *
     * @return Message[] The captured messages.
     */
    public static function getMessages(): array
    {
        return static::$messages;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function clear(string $queue = self::DEFAULT): void
    {
        static::$messages = array_filter(
            static::$messages,
            static fn(Message $message): bool => $message->getQueue() !== $queue
        ) |> array_values(...);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function complete(Message $message): void {}

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function discard(Message $message): void {}

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function fail(Message $message, Throwable|null $exception = null): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function forgetFailed(string $id, string $queue = self::DEFAULT): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getFailed(string $queue = self::DEFAULT): array
    {
        return [];
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function pop(string $queue = self::DEFAULT): Message|null
    {
        return null;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function push(Message $message): bool
    {
        static::$messages[] = clone $message;

        return true;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function queues(): array
    {
        return array_map(
            static fn(Message $message): string => $message->getQueue(),
            static::$messages
        ) |> array_unique(...) |> array_values(...);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function reset(string $queue = self::DEFAULT): void {}

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function retryFailed(string $id, string $queue = self::DEFAULT): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function stats(string $queue = self::DEFAULT): array
    {
        return [];
    }
}
