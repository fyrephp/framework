<?php
declare(strict_types=1);

namespace Tests\TestCase\Cache;

use Fyre\Cache\CacheManager;
use Fyre\Cache\Cacher;
use Fyre\Cache\Exceptions\CacheException;
use Fyre\Cache\Handlers\Memcached\MemcachedCacher;
use Fyre\Core\Container;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Tests\TestCase\Cache\Cacher\DecrementTestTrait;
use Tests\TestCase\Cache\Cacher\DeleteTestTrait;
use Tests\TestCase\Cache\Cacher\EmptyTestTrait;
use Tests\TestCase\Cache\Cacher\GetSetTestTrait;
use Tests\TestCase\Cache\Cacher\HasTestTrait;
use Tests\TestCase\Cache\Cacher\IncrementTestTrait;
use Tests\TestCase\Cache\Cacher\LockTestTrait;
use Tests\TestCase\Cache\Cacher\RememberTestTrait;
use Tests\TestCase\Cache\Cacher\TagsTestTrait;

use function getenv;
use function sleep;

#[RequiresPhpExtension('memcached')]
final class MemcachedTest extends TestCase
{
    use DecrementTestTrait;
    use DeleteTestTrait;
    use EmptyTestTrait;
    use GetSetTestTrait;
    use HasTestTrait;
    use IncrementTestTrait;
    use LockTestTrait;
    use RememberTestTrait;
    use TagsTestTrait;

    protected CacheManager $cacheManager;

    protected Cacher $cacher;

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function disallowedClearOptionsProvider(): array
    {
        return [
            'default options' => [[]],
            'prefixed cache' => [['prefix' => 'prefix.']],
            'prefixed cache with flush enabled' => [['prefix' => 'prefix.', 'flushServer' => true]],
            'zero prefix with flush enabled' => [['prefix' => '0', 'flushServer' => true]],
            'non-boolean flush option' => [['flushServer' => 'false']],
        ];
    }

    /**
     * @param array<string, mixed> $options The cache options.
     */
    #[DataProvider('disallowedClearOptionsProvider')]
    public function testClearRequiresUnprefixedServerFlush(array $options): void
    {
        $cache = $this->cacheManager->build([
            'className' => MemcachedCacher::class,
            'host' => getenv('MEMCACHED_HOST'),
            'port' => getenv('MEMCACHED_PORT'),
            ...$options,
        ]);

        $this->assertTrue($this->cacher->set('test', 'value'));
        $lock = $this->cacher->lock('test');
        $this->assertTrue($lock->acquire());

        $this->expectException(CacheException::class);
        $this->expectExceptionMessageIs('Memcached cache clear requires an empty prefix and flushServer enabled.');

        try {
            $cache->clear();
        } finally {
            $this->assertSame('value', $this->cacher->get('test'));
            $this->assertTrue($lock->release());
        }
    }

    public function testDebug(): void
    {
        $data = $this->cacher->__debugInfo();

        $this->assertArraysAreIdentical(
            [
                '[class]' => MemcachedCacher::class,
                'config' => [
                    'expire' => null,
                    'prefix' => '',
                    'host' => '[*****]',
                    'port' => '[*****]',
                    'weight' => 1,
                    'flushServer' => true,
                    'className' => MemcachedCacher::class,
                ],
                'connection' => '[Memcached]',
            ],
            $data
        );
    }

    public function testExpiredValue(): void
    {
        $this->cacher->set('test', 'value', 1);

        sleep(2);

        $this->assertNull(
            $this->cacher->get('test')
        );
    }

    public function testInvalidConnection(): void
    {
        $this->expectException(CacheException::class);
        $this->expectExceptionMessageIs('Memcache cache connection failed.');

        $this->cacheManager->build([
            'className' => MemcachedCacher::class,
            'port' => 1,
        ]);
    }

    public function testSetLongExpiry(): void
    {
        $this->assertTrue(
            $this->cacher->set('test', 'value', 2_592_001)
        );

        $this->assertSame(
            'value',
            $this->cacher->get('test')
        );
    }

    public function testSetMultipleLongExpiry(): void
    {
        $values = [
            'test1' => 'value1',
            'test2' => 'value2',
        ];

        $this->assertTrue(
            $this->cacher->setMultiple($values, 2_592_001)
        );

        $this->assertSame(
            $values,
            $this->cacher->getMultiple(['test1', 'test2'])
        );
    }

    #[Override]
    protected function setUp(): void
    {
        $this->cacheManager = new Container()
            ->use(CacheManager::class);

        $this->cacher = $this->cacheManager->build([
            'className' => MemcachedCacher::class,
            'host' => getenv('MEMCACHED_HOST'),
            'port' => getenv('MEMCACHED_PORT'),
            'flushServer' => true,
        ]);
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->cacher->clear();
    }
}
