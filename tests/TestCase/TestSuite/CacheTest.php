<?php
declare(strict_types=1);

namespace Tests\TestCase\TestSuite;

use Fyre\Cache\Handlers\Array\ArrayCacher;
use Fyre\Cache\Handlers\Null\NullCacher;
use Fyre\Core\Config;
use Fyre\Core\Engine;
use Fyre\Core\Loader;
use Fyre\TestSuite\TestCase;
use Fyre\TestSuite\Traits\CacheTestTrait;
use Override;

final class CacheTest extends TestCase
{
    use CacheTestTrait;

    public function testIsolatedCaches(): void
    {
        $default = $this->cacheManager->use();
        $secondary = $this->cacheManager->use('secondary');

        $this->assertInstanceOf(ArrayCacher::class, $default);
        $this->assertNull($default->get('key'));

        $default->set('key', 'value');

        $this->assertSame('value', $default->get('key'));
        $this->assertNull($secondary->get('key'));
    }

    public function testRestoresConfigurationAndState(): void
    {
        $config = $this->cacheConfigs;
        $this->cacheManager->use()->set('key', 'value');

        $this->tearDownCacheHandlers();

        $this->assertSame($config, $this->cacheManager->getConfig());
        $this->assertFalse($this->cacheManager->isEnabled());

        $this->setupCacheHandlers();

        $this->assertTrue($this->cacheManager->isEnabled());
        $this->assertNull($this->cacheManager->use()->get('key'));
    }

    public function testUnconfiguredCache(): void
    {
        $this->tearDownCacheHandlers();
        $this->cacheManager->clear();
        $this->cacheManager->enable();

        $this->setupCacheHandlers();

        $this->assertInstanceOf(ArrayCacher::class, $this->cacheManager->use());

        $this->tearDownCacheHandlers();

        $this->assertSame([], $this->cacheManager->getConfig());
        $this->assertTrue($this->cacheManager->isEnabled());
    }

    #[Override]
    protected function setUp(): void
    {
        $app = new Engine(new Loader());

        $app->use(Config::class)->set('App.debug', true);
        $app->use(Config::class)->set('Cache', [
            'default' => [
                'className' => NullCacher::class,
            ],
            'secondary' => [
                'className' => NullCacher::class,
            ],
        ]);

        Engine::setInstance($app);

        parent::setUp();
    }
}
