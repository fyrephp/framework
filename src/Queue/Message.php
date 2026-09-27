<?php
declare(strict_types=1);

namespace Fyre\Queue;

use Fyre\Core\Traits\DebugTrait;

use function array_map;
use function array_replace;
use function array_values;
use function class_exists;
use function count;
use function implode;
use function json_encode;
use function ksort;
use function md5;
use function method_exists;
use function min;
use function time;

/**
 * Represents a queued message payload and delivery constraints.
 *
 * Delay/expiry options are normalized into "after" and "before" timestamps at construction
 * time.
 */
class Message
{
    use DebugTrait;

    /**
     * @var array<string, mixed>
     */
    protected static array $defaults = [
        'className' => null,
        'method' => 'run',
        'arguments' => [],
        'config' => QueueManager::DEFAULT,
        'queue' => Queue::DEFAULT,
        'delay' => 0,
        'expires' => 0,
        'after' => null,
        'before' => null,
        'retry' => true,
        'maxRetries' => 5,
        'backoff' => 0,
        'unique' => false,
    ];

    /**
     * @var array<string, mixed>
     */
    protected array $config;

    protected int $retryAttempts = 0;

    /**
     * Constructs a Message.
     *
     * @param array<string, mixed> $options The message options.
     */
    public function __construct(array $options = [])
    {
        $this->config = array_replace(static::$defaults, $options);

        $this->config['backoff'] = array_map(
            static fn(mixed $delay): int => (int) $delay,
            (array) $this->config['backoff']
        ) |> array_values(...);

        if ($this->config['expires']) {
            $this->config['before'] ??= time() + $this->config['expires'];
        }

        if ($this->config['delay']) {
            $this->config['after'] ??= time() + $this->config['delay'];
        }

        unset($this->config['expires'], $this->config['delay']);
    }

    /**
     * Returns the timestamp when the message can be sent.
     *
     * @return int|null The timestamp when the message can be sent.
     */
    public function getAfter(): int|null
    {
        return $this->config['after'];
    }

    /**
     * Returns the message config.
     *
     * @return array<string, mixed> The message config.
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Returns the message hash.
     *
     * Note: The arguments array is sorted to produce a stable hash for uniqueness checks.
     *
     * @return string The message hash.
     */
    public function getHash(): string
    {
        $arguments = $this->config['arguments'];

        ksort($arguments);

        return implode([
            $this->config['className'],
            $this->config['method'],
            json_encode($arguments),
        ]) |> md5(...);
    }

    /**
     * Returns the message queue.
     *
     * @return string The message queue.
     */
    public function getQueue(): string
    {
        return $this->config['queue'];
    }

    /**
     * Returns the delay before the next retry.
     *
     * @return int The retry delay in seconds.
     */
    public function getRetryDelay(): int
    {
        $index = min(
            $this->retryAttempts - 1,
            count($this->config['backoff']) - 1
        );

        return $this->config['backoff'][$index] ?? 0;
    }

    /**
     * Checks whether the message has expired.
     *
     * @return bool Whether the message has expired.
     */
    public function isExpired(): bool
    {
        if (!$this->config['before']) {
            return false;
        }

        return $this->config['before'] < time();
    }

    /**
     * Checks whether the message is ready.
     *
     * @return bool Whether the message is ready.
     */
    public function isReady(): bool
    {
        if ($this->config['after'] === null) {
            return true;
        }

        return $this->config['after'] < time();
    }

    /**
     * Checks whether the message must be unique.
     *
     * @return bool Whether the message must be unique.
     */
    public function isUnique(): bool
    {
        return $this->config['unique'];
    }

    /**
     * Checks whether the message is valid.
     *
     * @return bool Whether the message is valid.
     */
    public function isValid(): bool
    {
        return class_exists($this->config['className']) && method_exists($this->config['className'], $this->config['method']);
    }

    /**
     * Checks whether the message should be retried.
     *
     * Note: This method increments the retry attempt counter.
     *
     * @return bool Whether the message should be retried.
     *
     * @phpstan-impure
     */
    public function shouldRetry(): bool
    {
        if (!$this->config['retry'] || $this->isExpired()) {
            return false;
        }

        return $this->retryAttempts++ < $this->config['maxRetries'] - 1;
    }
}
