<?php
declare(strict_types=1);

namespace Fyre\Queue\Handlers;

use Fyre\Core\Attributes\SensitivePropertyArray;
use Fyre\Core\Container;
use Fyre\Queue\Exceptions\QueueException;
use Fyre\Queue\FailedMessage;
use Fyre\Queue\Message;
use Fyre\Queue\Queue;
use InvalidArgumentException;
use Override;
use Redis;
use RedisException;
use Throwable;
use WeakMap;

use function bin2hex;
use function explode;
use function in_array;
use function is_string;
use function random_bytes;
use function serialize;
use function sprintf;
use function strlen;
use function strpbrk;
use function substr;
use function time;
use function unserialize;

/**
 * Queue implementation backed by Redis.
 *
 * Uses a Redis list for queued messages, sorted sets for delayed and processing messages,
 * and hashes for uniqueness checks and failed messages.
 */
class RedisQueue extends Queue
{
    protected const ID_LENGTH = 32;

    protected const NO_UNIQUE_HASH = '--------------------------------';

    protected const PAYLOAD_HEADER_LENGTH = 64;

    protected const RELEASE_LIMIT = 100;

    /**
     * @var array<string, mixed>
     */
    #[Override]
    protected static array $defaults = [
        'host' => '127.0.0.1',
        'password' => null,
        'port' => 6379,
        'database' => null,
        'prefix' => '',
        'timeout' => 0,
        'visibilityTimeout' => 300,
        'persist' => true,
        'tls' => false,
        'ssl' => [
            'key' => null,
            'cert' => null,
            'ca' => null,
        ],
    ];

    /**
     * @var array<string, mixed>
     */
    #[Override]
    #[SensitivePropertyArray([
        'host',
        'password',
        'port',
        'database',
        'ssl' => [
            'key',
            'cert',
            'ca',
        ],
    ])]
    protected array $config;

    protected Redis $connection;

    /**
     * @var WeakMap<Message, array{
     *     queue: string,
     *     payload: string,
     *     reservation: string,
     *     uniqueHash: string|null
     * }>
     */
    protected WeakMap $reservations;

    /**
     * Constructs a RedisQueue.
     *
     * @param Container $container The Container.
     * @param array<string, mixed> $options The queue options.
     *
     * @throws QueueException If the connection fails.
     * @throws InvalidArgumentException If a queue option is not valid.
     */
    public function __construct(Container $container, array $options = [])
    {
        parent::__construct($container, $options);

        $this->reservations = new WeakMap();

        if (
            !is_string($this->config['prefix']) ||
            strpbrk($this->config['prefix'], '*?[]\\') !== false
        ) {
            throw new InvalidArgumentException('Redis queue prefix must be a string without glob characters.');
        }

        try {
            $this->connection = new Redis();

            if ($this->config['visibilityTimeout'] <= 0) {
                throw new InvalidArgumentException('Redis queue option `visibilityTimeout` must be greater than 0.');
            }

            $tls = $this->config['tls'] ? 'tls://' : '';

            if (!$this->connection->connect(
                $tls.$this->config['host'],
                (int) $this->config['port'],
                (int) $this->config['timeout'],
                $this->config['persist'] ?
                    ($this->config['port'].$this->config['timeout'].$this->config['database']) :
                null,
                0,
                0,
                [
                    'ssl' => [
                        'local_pk' => $this->config['ssl']['key'] ?? null,
                        'local_cert' => $this->config['ssl']['cert'] ?? null,
                        'cafile' => $this->config['ssl']['ca'] ?? null,
                    ],
                ],
            )) {
                throw new QueueException('Redis queue connection failed.');
            }

            if ($this->config['password'] && !$this->connection->auth($this->config['password'])) {
                throw new QueueException('Redis queue authentication failed.');
            }

            if ($this->config['database'] && !$this->connection->select($this->config['database'])) {
                throw new InvalidArgumentException(sprintf(
                    'Redis queue database `%s` is not valid.',
                    $this->config['database']
                ));
            }

        } catch (RedisException $e) {
            throw new QueueException(sprintf(
                'Redis queue connection error: %s',
                $e->getMessage()
            ), previous: $e);
        }
    }

    /**
     * Releases the Redis connection.
     */
    public function __destruct()
    {
        $this->connection->close();
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function clear(string $queue = self::DEFAULT): void
    {
        $this->connection->del(
            $this->prepareKey($queue),
            $this->prepareKey($queue, 'delayed'),
            $this->prepareKey($queue, 'processing'),
            $this->prepareKey($queue, 'unique')
        );
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function complete(Message $message): void
    {
        $this->settle($message, 'complete');
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function discard(Message $message): void
    {
        $this->settle($message, 'discard');
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function fail(Message $message, Throwable|null $exception = null): bool
    {
        if (!isset($this->reservations[$message])) {
            return false;
        }

        $data = $this->reservations[$message];

        if ($message->shouldRetry()) {
            $payload = substr($data['payload'], 0, static::PAYLOAD_HEADER_LENGTH).serialize($message);
            $retryDelay = $message->getRetryDelay();
            $retryAt = $retryDelay > 0 ?
                time() + $retryDelay :
                null;

            return $this->settle($message, 'retry', $payload, $retryAt);
        }

        $failure = serialize(new FailedMessage($message, time(), $exception));

        $this->settle($message, 'failed', failure: $failure);

        return false;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function forgetFailed(string $id, string $queue = self::DEFAULT): bool
    {
        return $this->connection->hDel($this->prepareKey($queue, 'failures'), $id) === 1;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getFailed(string $queue = self::DEFAULT): array
    {
        $data = $this->prepareKey($queue, 'failures') |> $this->connection->hGetAll(...);
        $failures = [];

        foreach ($data as $id => $failure) {
            $failure = static::parseFailure($failure);

            if ($failure !== null) {
                $failures[$id] = $failure;
            }
        }

        return $failures;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function pop(string $queue = self::DEFAULT): Message|null
    {
        $this->releaseMessages($queue, 'delayed');
        $this->releaseMessages($queue, 'processing', true);

        $data = $this->reserve($queue);

        if ($data === null) {
            return null;
        }

        $payload = $data['payload'];
        $uniqueHash = static::getUniqueHash($payload);
        $message = substr($payload, static::PAYLOAD_HEADER_LENGTH) |> @unserialize(...);

        if (!($message instanceof Message)) {
            $this->settleReservation($queue, $data['reservation'], 'discard', $uniqueHash);

            return null;
        }

        $this->reservations[$message] = [
            'queue' => $queue,
            'payload' => $payload,
            'reservation' => $data['reservation'],
            'uniqueHash' => $uniqueHash,
        ];

        return $message;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function push(Message $message): bool
    {
        if ($message->isExpired()) {
            return false;
        }

        $queue = $message->getQueue();
        $uniqueHash = $message->isUnique() ?
            $message->getHash() :
            null;

        $payload = static::generateId()
            .($uniqueHash ?? static::NO_UNIQUE_HASH)
            .serialize($message);

        $after = $message->isReady() ?
            null :
            $message->getAfter();

        $result = $this->connection->eval(
            <<<'LUA'
                if ARGV[2] ~= '' then
                    if redis.call('hsetnx', KEYS[1], ARGV[2], 1) == 0 then
                        return 0
                    end
                end

                if ARGV[3] ~= '' then
                    redis.call('zadd', KEYS[3], ARGV[3], ARGV[1])
                else
                    redis.call('lpush', KEYS[2], ARGV[1])
                    redis.call('incr', KEYS[4])
                end

                return 1
                LUA,
            [
                $this->prepareKey($queue, 'unique'),
                $this->prepareKey($queue),
                $this->prepareKey($queue, 'delayed'),
                $this->prepareKey($queue, 'total'),
                $payload,
                $uniqueHash ?? '',
                $after === null ? '' : (string) $after,
            ],
            4
        );

        return (int) $result === 1;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function queues(): array
    {
        $iterator = null;
        $queues = [];

        while (($keys = $this->connection->scan($iterator, $this->prepareKey('*'), 50)) !== false) {
            foreach ($keys as $key) {
                $queue = explode(':', substr($key, strlen($this->config['prefix']) + 6), 2)[0];

                if ($queue && !in_array($queue, $queues, true)) {
                    $queues[] = $queue;
                }
            }
        }

        return $queues;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function reset(string $queue = self::DEFAULT): void
    {
        $this->prepareKey($queue, 'completed') |> $this->connection->del(...);
        $this->prepareKey($queue, 'failed') |> $this->connection->del(...);
        $this->prepareKey($queue, 'total') |> $this->connection->del(...);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function retryFailed(string $id, string $queue = self::DEFAULT): bool
    {
        $failure = $this->connection->hGet($this->prepareKey($queue, 'failures'), $id);

        if (!is_string($failure)) {
            return false;
        }

        $failure = static::parseFailure($failure);

        if ($failure === null) {
            return false;
        }

        if (!$this->push($failure->getMessage())) {
            return false;
        }

        return $this->forgetFailed($id, $queue);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function stats(string $queue = self::DEFAULT): array
    {
        return [
            'queued' => (int) ($this->prepareKey($queue) |> $this->connection->lLen(...)),
            'delayed' => (int) $this->connection->zCount($this->prepareKey($queue, 'delayed'), '-inf', '+inf'),
            'completed' => (int) ($this->prepareKey($queue, 'completed') |> $this->connection->get(...)),
            'failed' => (int) ($this->prepareKey($queue, 'failed') |> $this->connection->get(...)),
            'total' => (int) ($this->prepareKey($queue, 'total') |> $this->connection->get(...)),
        ];
    }

    /**
     * Returns the key for a queue with optional suffix.
     *
     * @param string $queue The queue name.
     * @param string $suffix The key suffix.
     * @return string The key.
     */
    protected function prepareKey(string $queue, string $suffix = ''): string
    {
        return $this->config['prefix'].'queue:'.$queue.($suffix ? ':'.$suffix : '');
    }

    /**
     * Releases messages that are ready to be queued.
     *
     * @param string $queue The queue name.
     * @param string $source The source key suffix.
     * @param bool $reserved Whether the source contains reservations.
     */
    protected function releaseMessages(string $queue, string $source, bool $reserved = false): void
    {
        $this->connection->eval(
            <<<'LUA'
                local items = redis.call(
                    'zrangebyscore',
                    KEYS[1],
                    '-inf',
                    ARGV[1],
                    'limit',
                    0,
                    ARGV[2]
                )

                local moved = 0

                for _, item in ipairs(items) do
                    if redis.call('zrem', KEYS[1], item) == 1 then
                        if ARGV[3] == '1' then
                            item = string.sub(item, tonumber(ARGV[4]) + 1)
                        end

                        redis.call('lpush', KEYS[2], item)
                        moved = moved + 1
                    end
                end

                if moved > 0 then
                    redis.call('incrby', KEYS[3], moved)
                end

                return moved
                LUA,
            [
                $this->prepareKey($queue, $source),
                $this->prepareKey($queue),
                $this->prepareKey($queue, 'total'),
                (string) time(),
                (string) static::RELEASE_LIMIT,
                $reserved ? '1' : '0',
                (string) static::ID_LENGTH,
            ],
            3
        );
    }

    /**
     * Reserves the next queued message.
     *
     * @param string $queue The queue name.
     * @return array{payload: string, reservation: string}|null The reservation data.
     */
    protected function reserve(string $queue): array|null
    {
        $receipt = static::generateId();

        $payload = $this->connection->eval(
            <<<'LUA'
                local payload = redis.call('rpop', KEYS[1])

                if not payload then
                    return false
                end

                redis.call('zadd', KEYS[2], ARGV[1], ARGV[2] .. payload)

                return payload
                LUA,
            [
                $this->prepareKey($queue),
                $this->prepareKey($queue, 'processing'),
                (string) (time() + $this->config['visibilityTimeout']),
                $receipt,
            ],
            2
        );

        if (!is_string($payload)) {
            return null;
        }

        return [
            'payload' => $payload,
            'reservation' => $receipt.$payload,
        ];
    }

    /**
     * Settles a message reservation.
     *
     * @param Message $message The Message.
     * @param 'complete'|'discard'|'failed'|'retry' $action The settlement action.
     * @param string $payload The retry payload.
     * @param int|null $retryAt The retry timestamp.
     * @param string $failure The serialized failure data.
     * @return bool Whether the reservation was settled.
     */
    protected function settle(
        Message $message,
        string $action,
        string $payload = '',
        int|null $retryAt = null,
        string $failure = ''
    ): bool {
        if (!isset($this->reservations[$message])) {
            return false;
        }

        $data = $this->reservations[$message];

        $result = $this->settleReservation(
            $data['queue'],
            $data['reservation'],
            $action,
            $data['uniqueHash'],
            $payload,
            $retryAt,
            $failure
        );

        unset($this->reservations[$message]);

        return $result;
    }

    /**
     * Settles a raw message reservation.
     *
     * @param string $queue The queue name.
     * @param string $reservation The reservation data.
     * @param 'complete'|'discard'|'failed'|'retry' $action The settlement action.
     * @param string|null $uniqueHash The uniqueness hash.
     * @param string $payload The retry payload.
     * @param int|null $retryAt The retry timestamp.
     * @param string $failure The serialized failure data.
     * @return bool Whether the reservation was settled.
     */
    protected function settleReservation(
        string $queue,
        string $reservation,
        string $action,
        string|null $uniqueHash = null,
        string $payload = '',
        int|null $retryAt = null,
        string $failure = ''
    ): bool {
        $result = $this->connection->eval(
            <<<'LUA'
                if redis.call('zrem', KEYS[1], ARGV[1]) == 0 then
                    return 0
                end

                if ARGV[3] == 'retry' then
                    redis.call('incr', KEYS[4])

                    if ARGV[5] ~= '' then
                        redis.call('zadd', KEYS[7], ARGV[5], ARGV[4])
                    else
                        redis.call('lpush', KEYS[5], ARGV[4])
                        redis.call('incr', KEYS[6])
                    end

                    return 1
                end

                if ARGV[3] == 'complete' then
                    redis.call('incr', KEYS[3])
                elseif ARGV[3] == 'failed' then
                    redis.call('incr', KEYS[4])
                    redis.call('hset', KEYS[8], ARGV[6], ARGV[7])
                end

                if ARGV[2] ~= '' then
                    redis.call('hdel', KEYS[2], ARGV[2])
                end

                return 1
                LUA,
            [
                $this->prepareKey($queue, 'processing'),
                $this->prepareKey($queue, 'unique'),
                $this->prepareKey($queue, 'completed'),
                $this->prepareKey($queue, 'failed'),
                $this->prepareKey($queue),
                $this->prepareKey($queue, 'total'),
                $this->prepareKey($queue, 'delayed'),
                $this->prepareKey($queue, 'failures'),
                $reservation,
                $uniqueHash ?? '',
                $action,
                $payload,
                $retryAt === null ? '' : (string) $retryAt,
                substr($reservation, static::ID_LENGTH, static::ID_LENGTH),
                $failure,
            ],
            8
        );

        return (int) $result === 1;
    }

    /**
     * Generates a queue identifier.
     *
     * @return string The identifier.
     */
    protected static function generateId(): string
    {
        return random_bytes(16) |> bin2hex(...);
    }

    /**
     * Returns the uniqueness hash from a payload.
     *
     * @param string $payload The payload.
     * @return string|null The uniqueness hash.
     */
    protected static function getUniqueHash(string $payload): string|null
    {
        if (strlen($payload) < static::PAYLOAD_HEADER_LENGTH) {
            return null;
        }

        $uniqueHash = substr($payload, static::ID_LENGTH, static::ID_LENGTH);

        return $uniqueHash === static::NO_UNIQUE_HASH ?
            null :
            $uniqueHash;
    }

    /**
     * Parses serialized failure data.
     *
     * @param string $failure The serialized failure data.
     * @return FailedMessage|null The failure data.
     */
    protected static function parseFailure(string $failure): FailedMessage|null
    {
        try {
            $failure = @unserialize($failure);
        } catch (Throwable) {
            return null;
        }

        return $failure instanceof FailedMessage ?
            $failure :
            null;
    }
}
