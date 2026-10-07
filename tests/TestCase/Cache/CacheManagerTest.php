<?php
declare(strict_types=1);

namespace Tests\TestCase\Cache;

use Fyre\Cache\CacheManager;
use Fyre\Cache\Cacher;
use Fyre\Cache\Exceptions\InvalidArgumentException;
use Fyre\Cache\Handlers\Array\ArrayCacher;
use Fyre\Cache\Handlers\Null\NullCacher;
use Fyre\Cache\Lock;
use Fyre\Cache\TaggedCacher;
use Fyre\Core\Config;
use Fyre\Core\Container;
use Fyre\Core\Traits\DebugTrait;
use Fyre\Core\Traits\MacroTrait;
use Override;
use PHPUnit\Framework\TestCase;

use function class_uses;

final class CacheManagerTest extends TestCase
{
    protected CacheManager $cacheManager;

    public function testBuild(): void
    {
        $this->assertInstanceOf(
            ArrayCacher::class,
            $this->cacheManager->build([
                'className' => ArrayCacher::class,
            ])
        );
    }

    public function testBuildDisabled(): void
    {
        $this->cacheManager->disable();

        $handler1 = $this->cacheManager->build([
            'className' => ArrayCacher::class,
        ]);
        $handler2 = $this->cacheManager->build([
            'className' => ArrayCacher::class,
        ]);

        $this->assertSame($handler1, $handler2);

        $this->assertInstanceOf(
            NullCacher::class,
            $handler1
        );
    }

    public function testBuildInvalidHandler(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Cacher `Invalid` must extend `Fyre\Cache\Cacher`.');

        $this->cacheManager->build([
            'className' => 'Invalid',
        ]);
    }

    public function testDebug(): void
    {
        $this->assertContains(
            DebugTrait::class,
            class_uses(CacheManager::class)
        );

        $this->assertContains(
            DebugTrait::class,
            class_uses(TaggedCacher::class)
        );

        $this->assertContains(
            DebugTrait::class,
            class_uses(Lock::class)
        );
    }

    public function testDisable(): void
    {
        $this->assertSame(
            $this->cacheManager,
            $this->cacheManager->disable()
        );

        $this->assertFalse(
            $this->cacheManager->isEnabled()
        );

        $this->assertInstanceOf(
            NullCacher::class,
            $this->cacheManager->use()
        );
    }

    public function testDisableLoadedHandler(): void
    {
        $handler = $this->cacheManager->use();
        $handler->set('test', 'value');

        $this->cacheManager->disable();

        $this->assertInstanceOf(
            NullCacher::class,
            $this->cacheManager->use()
        );
        $this->assertNull($this->cacheManager->use()->get('test'));

        $this->cacheManager->enable();

        $this->assertSame($handler, $this->cacheManager->use());
        $this->assertSame('value', $this->cacheManager->use()->get('test'));
    }

    public function testEnable(): void
    {
        $this->cacheManager->disable();

        $this->assertSame(
            $this->cacheManager,
            $this->cacheManager->enable()
        );

        $this->assertTrue(
            $this->cacheManager->isEnabled()
        );

        $this->assertInstanceOf(
            ArrayCacher::class,
            $this->cacheManager->use()
        );
    }

    public function testEnableAfterUsingDisabledHandler(): void
    {
        $this->cacheManager->disable();

        $this->assertInstanceOf(
            NullCacher::class,
            $this->cacheManager->use()
        );

        $this->cacheManager->enable();

        $this->assertInstanceOf(
            ArrayCacher::class,
            $this->cacheManager->use()
        );
    }

    public function testGetConfig(): void
    {
        $config = $this->cacheManager->getConfig();

        $this->assertIsArray($config);
        $this->assertArraysAreIdentical(
            [
                'default' => [
                    'className' => ArrayCacher::class,
                    'prefix' => 'prefix.',
                ],
                'data' => [
                    'className' => ArrayCacher::class,
                    'prefix' => 'data.',
                ],
            ],
            $config
        );
    }

    public function testGetConfigKey(): void
    {
        $config = $this->cacheManager->getConfig('data');

        $this->assertIsArray($config);
        $this->assertArraysAreIdentical(
            [
                'className' => ArrayCacher::class,
                'prefix' => 'data.',
            ],
            $config
        );
    }

    public function testIsLoaded(): void
    {
        $this->cacheManager->use();

        $this->assertTrue(
            $this->cacheManager->isLoaded()
        );
    }

    public function testIsLoadedInvalid(): void
    {
        $this->assertFalse(
            $this->cacheManager->isLoaded('test')
        );
    }

    public function testIsLoadedKey(): void
    {
        $this->cacheManager->use('data');

        $this->assertTrue(
            $this->cacheManager->isLoaded('data')
        );
    }

    public function testMacro(): void
    {
        $this->assertContains(
            MacroTrait::class,
            class_uses(Cacher::class)
        );
    }

    public function testSetConfig(): void
    {
        $this->assertSame(
            $this->cacheManager,
            $this->cacheManager->setConfig('test', [
                'className' => ArrayCacher::class,
            ])
        );

        $config = $this->cacheManager->getConfig('test');

        $this->assertIsArray($config);
        $this->assertArraysAreIdentical(
            [
                'className' => ArrayCacher::class,
            ],
            $config
        );
    }

    public function testSetConfigExists(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Cache config `default` already exists.');

        $this->cacheManager->setConfig('default', [
            'className' => ArrayCacher::class,
        ]);
    }

    public function testUnload(): void
    {
        $this->cacheManager->use();

        $this->assertSame(
            $this->cacheManager,
            $this->cacheManager->unload()
        );

        $this->assertFalse(
            $this->cacheManager->isLoaded()
        );
        $this->assertFalse(
            $this->cacheManager->hasConfig()
        );
    }

    public function testUnloadInvalid(): void
    {
        $this->assertSame(
            $this->cacheManager,
            $this->cacheManager->unload('test')
        );
    }

    public function testUnloadKey(): void
    {
        $this->cacheManager->use('data');

        $this->assertSame(
            $this->cacheManager,
            $this->cacheManager->unload('data')
        );

        $this->assertFalse(
            $this->cacheManager->isLoaded('data')
        );
        $this->assertFalse(
            $this->cacheManager->hasConfig('data')
        );
    }

    public function testUse(): void
    {
        $handler1 = $this->cacheManager->use();
        $handler2 = $this->cacheManager->use();

        $this->assertSame($handler1, $handler2);

        $this->assertInstanceOf(
            ArrayCacher::class,
            $handler1
        );
    }

    public function testUseDisabledInvalid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Cacher `` must extend `Fyre\Cache\Cacher`.');

        $this->cacheManager->disable();

        $this->cacheManager->use('invalid');
    }

    #[Override]
    protected function setUp(): void
    {
        $container = new Container();
        $container->singleton(Config::class);
        $container->use(Config::class)->set('Cache', [
            'default' => [
                'className' => ArrayCacher::class,
                'prefix' => 'prefix.',
            ],
            'data' => [
                'className' => ArrayCacher::class,
                'prefix' => 'data.',
            ],
        ]);
        $this->cacheManager = $container->use(CacheManager::class);
    }
}
