# Test configuration

The application test bootstrap owns test credentials, configuration loading, schema creation, and application boot. Load test settings before resolving services or models; the framework does not detect PHPUnit or switch connections automatically.

## Database connections

Configure `Database.test` explicitly. For additional application connections, configure `Database.test_<name>`. Before booting the application or resolving models, add the aliases:

```php
use Fyre\DB\ConnectionManager;
use Fyre\TestSuite\ConnectionHelper;

ConnectionHelper::addTestAliases($app->use(ConnectionManager::class));
```

This maps `default` to `test` and, for example, `reporting` to `test_reporting`. All targets must exist before any aliases are added. A connection that has already been loaded cannot be redirected. Repeating the helper with the same mappings is allowed.

For custom names, use `ConnectionManager::alias($source, $alias)` directly:

```php
$app->use(ConnectionManager::class)->alias('test_reporting', 'reporting');
```

Automatic fixtures require their models' write connections to resolve to configured `test` or `test_*` connections. Setup checks every affected connection before changing data. Cleanup truncates each table through its own write connection, including associated and junction tables.

The application must supply separate test databases and prepare their schemas. A test name does not verify the credentials or database contents.

## Queued jobs

Use `QueueTestTrait` to capture dispatches without connecting to Redis or executing jobs:

```php
use App\Jobs\SendEmail;
use Fyre\Queue\QueueManager;
use Fyre\TestSuite\TestCase;
use Fyre\TestSuite\Traits\QueueTestTrait;

final class EmailJobTest extends TestCase
{
    use QueueTestTrait;

    public function testDispatch(): void
    {
        $this->app->use(QueueManager::class)->push(SendEmail::class, ['userId' => 1], ['queue' => 'emails']);

        $this->assertJobQueued(SendEmail::class, ['userId' => 1], ['queue' => 'emails']);
        $this->assertJobCount(1);
    }
}
```

The trait replaces every configured queue with `Fyre\TestSuite\Queue\Handlers\TestQueue`, clears captured messages between tests, and restores the original configuration afterward. When no queue is configured, it supplies a default capture handler. `getQueuedMessages()` returns the captured `Message` objects; `assertNoJobsQueued()` checks that no dispatches occurred.

`assertJobQueued()` compares the class, optional exact arguments, and any supplied message options. Delay and expiry options are normalized to `after` and `before` timestamps. The capture handler does not simulate processing, retries, or uniqueness. Test job execution directly, and use a real isolated broker for queue behavior tests.

For real Redis tests, the application configures a dedicated Redis database and an optional `prefix`. The prefix applies to messages, reservations, failures, uniqueness keys, statistics, and queue discovery. Use a distinct prefix per parallel test process.

## Cache

To disable cache in the application test bootstrap:

```php
use Fyre\Cache\CacheManager;

$app->use(CacheManager::class)->disable();
```

Subsequent `use()` calls return a null handler even if a real handler was loaded earlier. Re-enabling restores the loaded handler. Previously obtained handler references retain their behavior.

For tests that need functioning cache, add `Fyre\TestSuite\Traits\CacheTestTrait` to a framework `TestCase`. It replaces configured caches with enabled, isolated `ArrayCacher` instances and restores the original configuration and enabled state afterward.

Queue and cache traits run after `TestCase::setUp()`. If application boot or fixture setup uses these services, the application bootstrap must configure test handlers before that work starts. Traits cannot replace references already held by other services.

## Error handling

Keep PHPUnit's PHP error handling in place by calling `unregister()` if application bootstrap registered the framework handler. Disable CLI rendering so in-process HTTP tests receive responses:

```php
use Fyre\Core\ErrorHandler;

$app->use(ErrorHandler::class)->unregister();
$app->use(ErrorHandler::class)->disableCli();
```

`IntegrationTestTrait` normally retains error response rendering. Call `$this->disableErrorRendering()` when a test expects the original exception to propagate. The helper rethrows through the existing `Error.beforeRender` event, including middleware inside groups. Logging still occurs before that event. Call `$this->enableErrorRendering()` to restore rendering; test cleanup also removes the helper's listener.

## Related

- [Testing](index.md)
- [Fixtures](fixtures.md)
- [Integration Testing](integration.md)
- [Queues](../queue/index.md)
- [Cache](../cache/index.md)
