<?php
declare(strict_types=1);

namespace Tests\TestCase\Router\Router;

use Fyre\Http\ServerRequest;
use Fyre\Router\Router;
use Fyre\Router\Routes\ControllerRoute;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Mock\Controllers\HomeController;

trait PrefixTestTrait
{
    /**
     * @return array<string, array{string}>
     */
    public static function prefixProvider(): array
    {
        return [
            'prefix' => ['prefix'],
            'leading slash' => ['/prefix'],
            'trailing slash' => ['prefix/'],
        ];
    }

    public function testGroupPrefixClearedAfterException(): void
    {
        $router = $this->container->use(Router::class);

        try {
            $router->group(
                static fn(): never => throw new RuntimeException('Test exception.'),
                prefix: 'prefix'
            );
        } catch (RuntimeException $e) {
            $this->assertSame(
                'Test exception.',
                $e->getMessage()
            );
        }

        $route = $router->get('home', HomeController::class);

        $this->assertSame(
            '/home',
            $route->getPath()
        );
    }

    #[DataProvider('prefixProvider')]
    public function testPrefix(string $prefix): void
    {
        $router = $this->container->use(Router::class);

        $router->group(static function(Router $router): void {
            $router->get('home', HomeController::class);
        }, prefix: $prefix);

        $request = $this->container->build(ServerRequest::class, [
            'options' => [
                'uri' => '/prefix/home',
            ],
        ]);

        $route = $router->parseRequest($request)->getAttribute('route');

        $this->assertInstanceOf(
            ControllerRoute::class,
            $route
        );

        $this->assertSame(
            HomeController::class,
            $route->getController()
        );
    }

    public function testPrefixDeep(): void
    {
        $router = $this->container->use(Router::class);

        $router->group(static function(Router $router): void {
            $router->group(static function(Router $router): void {
                $router->get('home', HomeController::class);
            }, prefix: 'deep');
        }, prefix: 'prefix');

        $request = $this->container->build(ServerRequest::class, [
            'options' => [
                'uri' => '/prefix/deep/home',
            ],
        ]);

        $route = $router->parseRequest($request)->getAttribute('route');

        $this->assertInstanceOf(
            ControllerRoute::class,
            $route
        );

        $this->assertSame(
            HomeController::class,
            $route->getController()
        );
    }

    public function testPrefixEmptyRoute(): void
    {
        $router = $this->container->use(Router::class);

        $router->group(static function(Router $router): void {
            $router->get('', HomeController::class);
        }, prefix: 'prefix');

        $request = $this->container->build(ServerRequest::class, [
            'options' => [
                'uri' => '/prefix',
            ],
        ]);

        $route = $router->parseRequest($request)->getAttribute('route');

        $this->assertInstanceOf(
            ControllerRoute::class,
            $route
        );

        $this->assertSame(
            HomeController::class,
            $route->getController()
        );
    }
}
