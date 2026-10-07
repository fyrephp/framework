<?php
declare(strict_types=1);

namespace Fyre\TestSuite\Traits;

use Fyre\Cache\CacheManager;
use Fyre\Cache\Handlers\Array\ArrayCacher;
use Fyre\TestSuite\TestCase;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

/**
 * Uses isolated in-memory caches and restores cache configuration after each test.
 *
 * @phpstan-require-extends TestCase
 */
trait CacheTestTrait
{
    /**
     * @var array<string, array<string, mixed>>
     */
    protected array $cacheConfigs = [];

    protected bool $cacheEnabled;

    protected CacheManager $cacheManager;

    /**
     * Replaces configured caches with enabled in-memory handlers.
     */
    #[Before(-1)]
    protected function setupCacheHandlers(): void
    {
        $this->cacheManager = $this->app->use(CacheManager::class);
        $this->cacheConfigs = $this->cacheManager->getConfig() ?? [];
        $this->cacheEnabled = $this->cacheManager->isEnabled();

        $this->cacheManager->clear();
        $this->cacheManager->enable();

        $configs = $this->cacheConfigs ?: [CacheManager::DEFAULT => []];

        foreach ($configs as $key => $config) {
            $config['className'] = ArrayCacher::class;

            $this->cacheManager->setConfig($key, $config);
        }
    }

    /**
     * Restores cache configuration and its enabled state.
     */
    #[After]
    protected function tearDownCacheHandlers(): void
    {
        if (!isset($this->cacheManager)) {
            return;
        }

        $this->cacheManager->clear();

        foreach ($this->cacheConfigs as $key => $config) {
            $this->cacheManager->setConfig($key, $config);
        }

        if ($this->cacheEnabled) {
            $this->cacheManager->enable();
        } else {
            $this->cacheManager->disable();
        }
    }
}
