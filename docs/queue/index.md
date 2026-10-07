# Queue

Use queue handlers when you want to move slow or retryable work out of the main request or command flow.

Queue lets you push jobs, delay them, retry them with backoff, retain terminal failures, enforce uniqueness, and process them with long-running workers.

## Table of Contents

- [Start here](#start-here)
- [Queue overview](#queue-overview)
- [Configuring queue handlers](#configuring-queue-handlers)
  - [Base handler options](#base-handler-options)
  - [Example configuration](#example-configuration)
- [Built-in queue handlers](#built-in-queue-handlers)
  - [Redis](#redis)
- [Writing jobs](#writing-jobs)
- [Pushing jobs](#pushing-jobs)
  - [Message options](#message-options)
- [Processing jobs](#processing-jobs)
- [Inspecting queues](#inspecting-queues)
- [Recovering failed jobs](#recovering-failed-jobs)
- [Lifecycle events](#lifecycle-events)
- [Behavior notes](#behavior-notes)
- [Related](#related)

## Start here

The usual queue workflow is:

1. Configure a handler under the `Queue` config key.
2. Write a job class with a `run()` method.
3. Push jobs with `QueueManager::push()` or `queue()`.
4. Run a worker to process jobs.
5. Inspect queue health with `stats()` or `queue:stats`, and recover terminal failures with the `queue:failed`, `queue:retry`, and `queue:purge` commands.

```php
use Fyre\Queue\QueueManager;

class SendWelcomeEmailJob
{
    public function run(Mailer $mailer, string $email): void
    {
        $mailer->sendWelcome($email);
    }
}

$queues = app(QueueManager::class);

$queues->push(SendWelcomeEmailJob::class, [
    'email' => 'user@example.com',
]);
```

The `queue($className, $arguments, $options)` helper pushes a job through the shared `QueueManager`; see [Helpers](../core/helpers.md).

## Queue overview

A queue setup has four parts: a configured handler, logical queue names such as `emails` or `search`, PHP job classes with their delivery options, and workers that consume those jobs.

Two queue settings are easy to confuse:

- `config` selects which configured handler to use
- `queue` selects which logical queue name inside that handler to use

Use `config` when you need different backends or connections. Use `queue` when you want to separate workloads within the same backend.

## Configuring queue handlers

Queue handler configuration is read from the `Queue` key in your app config. Each named entry is an options array used to build a handler.

### Base handler options

These options apply to all handlers:

- `className` (`class-string`) - handler class name, which must extend `Queue`

Other options depend on the selected handler.

### Example configuration

```php
use Fyre\Queue\Handlers\RedisQueue;

return [
    'Queue' => [
        'default' => [
            'className' => RedisQueue::class,
            'host' => '127.0.0.1',
            'port' => 6379,
        ],
    ],
];
```

Use `QueueManager::use()` to get a shared handler for one of those config keys:

```php
$queue = $queues->use('default');
$stats = $queue->stats();
```

If you need a one-off handler without storing it under a config key, pass its options directly to `QueueManager::build()`. Runtime configuration is available through `getConfig()` and `setConfig()`; `setConfig()` requires a key that has not already been registered.

## Built-in queue handlers

The framework ships built-in handlers under `Fyre\Queue\Handlers\*`.

### Redis

`RedisQueue` stores queued messages in Redis and is a good fit when you need delayed jobs, retries, and simple queue inspection.

It requires the `redis` PHP extension.

Options:

- `host` (`string`) - Redis host (default: `127.0.0.1`)
- `port` (`int`) - Redis port (default: `6379`)
- `password` (`string|null`) - Redis password (default: `null`)
- `database` (`int|null`) - Redis database index (default: `null`)
- `prefix` (`string`) - prefix for every queue storage key and queue discovery (default: `''`); glob characters (`*`, `?`, `[`, `]`, and `\`) are not allowed
- `timeout` (`int`) - connection timeout in seconds (default: `0`)
- `visibilityTimeout` (`int`) - seconds before an uncompleted job can be delivered again (default: `300`)
- `persist` (`bool`) - whether to use a persistent connection (default: `true`)
- `tls` (`bool`) - whether to connect using `tls://` (default: `false`)
- `ssl` (`array`) - TLS client settings (`key`, `cert`, `ca`)

`RedisQueue` stores `Message` objects using PHP serialization, so treat the Redis instance as trusted infrastructure and lock it down accordingly.

## Writing jobs

Jobs are plain classes with a method that the worker can call. The default method name is `run`.

Prefer named arguments when you push jobs so the call stays readable and stable if you reorder parameters later. You can also type-hint services, and the container will resolve them when the job runs.

```php
class GenerateReportJob
{
    public function run(ReportService $reports, int $reportId): void
    {
        $reports->generate($reportId);
    }
}

$queues->push(GenerateReportJob::class, [
    'reportId' => $reportId,
]);
```

A job is treated as failed when it returns `false` or throws an exception. Any other return value is treated as success.

## Pushing jobs

Use `QueueManager::push()` to enqueue a job class and method call.

```php
$queues->push(SearchIndexJob::class, [
    'postId' => $postId,
], [
    'config' => 'default',
    'queue' => 'search',
    'delay' => 60,
    'unique' => true,
]);
```

To call a method other than `run`, set the `method` option:

```php
$queues->push(CacheWarmupJob::class, [
    'userId' => $userId,
], [
    'method' => 'handle',
]);
```

### Message options

The most common message options are:

- `method` (`string`) - job method name (default: `run`)
- `config` (`string`) - queue handler config key (default: `default`)
- `queue` (`string`) - logical queue name inside the handler (default: `default`)
- `delay` (`int`) - delay in seconds before the job becomes ready
- `expires` (`int`) - number of seconds before the job expires
- `retry` (`bool`) - whether retries are allowed (default: `true`)
- `maxRetries` (`int`) - maximum total execution attempts, including the initial attempt (default: `5`)
- `backoff` (`int|int[]`) - delay in seconds before each retry; an array selects a delay by retry attempt and reuses its final value (default: `0`)
- `unique` (`bool`) - whether the handler should enforce uniqueness (default: `false`)

For more direct control, you can also set absolute `after` and `before` timestamps instead of `delay` and `expires`.

## Processing jobs

Jobs are processed by a [Queue Worker](worker.md). The worker pops the next available message, executes the job, and marks it as complete or failed.

The built-in way to run a worker is the `queue:worker` console command; see [Console Commands](../console/commands.md#queueworker).

Queues are designed for at-least-once processing. `RedisQueue` reserves each popped job until it is completed, failed, or discarded. If the worker stops first, the job becomes available again after `visibilityTimeout` seconds.

A job may run more than once, so prefer idempotent job design and set `visibilityTimeout` longer than the expected job runtime.

When a failed job can be retried, a positive `backoff` places it in the existing delayed queue until the selected delay has elapsed. When retries are disabled or exhausted, `RedisQueue` retains the message config, failure time, and exception metadata. A job that returns `false` has no exception metadata.

## Inspecting queues

Use `Queue::stats()` to inspect the current queue state and `Queue::queues()` to list known queue names.

```php
$queue = $queues->use();

$queueNames = $queue->queues();
$stats = $queue->stats('search');
```

`RedisQueue` reports these statistics:

- `queued` - messages currently ready for a worker
- `delayed` - messages waiting for their `after` timestamp
- `completed` - successful completions since the statistics were last reset
- `failed` - failed execution attempts since the last reset, including attempts that were retried
- `total` - delivery attempts made available since the last reset, including retries and recovered reservations

`reset($queue)` clears the `completed`, `failed`, and `total` counters. `clear($queue)` removes ready, delayed, and reserved jobs together with their uniqueness keys, but leaves statistics and retained terminal failures intact.

You can also inspect queue stats from the CLI using `queue:stats`; see [Console Commands](../console/commands.md#queuestats).

```bash
app queue:stats
app queue:stats --config=default --queue=search
```

## Recovering failed jobs

Use `Queue::getFailed()` to inspect terminal failures retained for a queue. The returned `FailedMessage` records are indexed by their 32-character job ID and remain until they are retried or forgotten.

Use `retryFailed()` to construct and enqueue a fresh `Message` from the stored config. Its retry attempt count starts again at zero, and the retained record is removed only when the message is accepted by the queue. Use `forgetFailed()` to remove a retained record without retrying it.

The same operations are available through the console. `queue:failed` displays the failure time in UTC.

`queue:retry` and `queue:purge` request confirmation whenever at least one failure matches, including when explicit IDs are supplied. Use `--force` to skip confirmation in non-interactive scripts.

```bash
app queue:failed --config=default --queue=search --class='App\Jobs\SearchIndexJob'
app queue:retry 0123456789abcdef0123456789abcdef --config=default --queue=search
app queue:retry --config=default --queue=search --class='App\Jobs\SearchIndexJob'
app queue:purge 0123456789abcdef0123456789abcdef --config=default --queue=search
app queue:purge --config=default --queue=search --class='App\Jobs\SearchIndexJob'
```

Retained exception metadata includes the exception class, message, code, file, line, and stack trace. `FailedMessage::getExceptionCode()` returns `int|string|null`, preserving string SQLSTATE codes from PDO exceptions. It returns `null` when no exception was recorded. Treat access to the queue backend and command output as sensitive.

## Lifecycle events

The worker dispatches queue lifecycle events through the event system:

- `Queue.start` - `message`
- `Queue.success` - `message`
- `Queue.failure` - `message`, `shouldRetry`
- `Queue.exception` - `message`, `exception`, `shouldRetry`
- `Queue.invalid` - `message`

For event listening patterns, see [Events](../events/index.md).

## Behavior notes

- `delay` and `expires` are converted into absolute `after` and `before` timestamps when the `Message` is created.
- Invalid messages are skipped and emit `Queue.invalid`; expired messages are dropped silently.
- `QueueManager::push()` does not return whether the handler accepted the message, so uniqueness or expiry can prevent enqueueing without raising an error.
- `Message::shouldRetry()` increments the retry counter, so call it only once for a given failure.
- `maxRetries` includes the initial execution, so a value of `5` permits the initial attempt and up to four retries.
- An integer `backoff` applies to every retry. An array applies successive delays and reuses its final value when there are more retries than values.
- `RedisQueue` holds a message's uniqueness key while it is delayed, queued, processing, or waiting for a retry. The key is removed when the message is completed, permanently failed, or discarded.
- Terminal failure records are separate from the `failed` statistics counter. Resetting statistics does not remove retained failures.
- `RedisQueue` storage is internal to the handler. Drain or clear existing queues before upgrading from a version that used a different storage format.
- Delays and expiries depend on system time, so keep worker hosts time-synced.

## Related

- [Config](../core/config.md)
- [Helpers](../core/helpers.md)
- [Queue Worker](worker.md)
- [Console Commands](../console/commands.md)
- [Events](../events/index.md)
