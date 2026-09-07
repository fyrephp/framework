<?php
declare(strict_types=1);

namespace Tests\TestCase\Core;

use Closure;
use Fyre\Core\Container;
use Fyre\Core\Exceptions\ContainerException;
use Fyre\Core\Exceptions\ContainerNotFoundException;
use Fyre\Core\Traits\DebugTrait;
use Fyre\Core\Traits\MacroTrait;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Mock\Core\Container\ArgumentService;
use Tests\Mock\Core\Container\CircularDependency;
use Tests\Mock\Core\Container\CircularService;
use Tests\Mock\Core\Container\ContainerService;
use Tests\Mock\Core\Container\InnerService;
use Tests\Mock\Core\Container\InvokableClass;
use Tests\Mock\Core\Container\Item;
use Tests\Mock\Core\Container\ItemContext;
use Tests\Mock\Core\Container\ItemService;
use Tests\Mock\Core\Container\OuterService;
use Tests\Mock\Core\Container\Service;

use function class_uses;

final class ContainerTest extends TestCase
{
    protected Container $container;

    /**
     * @return array<string, array{Closure(): (array{0: class-string|object, 1?: string}|object|string)}>
     */
    public static function callRepresentationProvider(): array
    {
        return [
            'object method array' => [static fn(): array => [new Service(), 'value']],
            'static method array' => [static fn(): array => [Service::class, 'staticValue']],
            'class method array' => [static fn(): array => [Service::class, 'value']],
            'invokable object array' => [static fn(): array => [new InvokableClass()]],
            'invokable class array' => [static fn(): array => [InvokableClass::class]],
            'invokable class' => [static fn(): string => InvokableClass::class],
            'invokable object' => [static fn(): InvokableClass => new InvokableClass()],
            'class method string' => [static fn(): string => Service::class.'::value'],
            'static method string' => [static fn(): string => Service::class.'::staticValue'],
        ];
    }

    public function testBuild(): void
    {
        $service = $this->container->build(Service::class);

        $this->assertInstanceOf(Service::class, $service);
    }

    public function testBuildArguments(): void
    {
        $argumentService = $this->container->build(ArgumentService::class, ['a' => 4, 'b' => 5, 'c' => 6]);

        $this->assertInstanceOf(ArgumentService::class, $argumentService);

        $this->assertArraysAreIdentical(
            [4, 5, 6],
            $argumentService->getArguments()
        );
    }

    public function testBuildArgumentsDefaults(): void
    {
        $argumentService = $this->container->build(ArgumentService::class, ['b' => 5]);

        $this->assertInstanceOf(ArgumentService::class, $argumentService);

        $this->assertArraysAreIdentical(
            [1, 5, 3],
            $argumentService->getArguments()
        );
    }

    public function testBuildCircularDependency(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessageIs(
            'Class `'.CircularService::class.'` is dependent on itself. (`'.CircularService::class.'` > `'.CircularDependency::class.'`)'
        );

        $this->container->build(CircularService::class);
    }

    public function testBuildContainerDependency(): void
    {
        $containerService = $this->container->build(ContainerService::class);

        $this->assertInstanceOf(ContainerService::class, $containerService);

        $this->assertSame(
            $this->container,
            $containerService->getContainer()
        );
    }

    public function testBuildContext(): void
    {
        $itemService = $this->container->build(ItemService::class);

        $item = $itemService->getItem();

        $this->assertInstanceOf(
            Item::class,
            $item
        );

        $this->assertSame(
            'test',
            $item->getValue()
        );
    }

    public function testBuildContextFromBinding(): void
    {
        $this->container->bindAttribute(ItemContext::class, function(Container $container): Item {
            $this->assertSame(
                $this->container,
                $container
            );

            return new Item('other');
        });

        $itemService = $this->container->build(ItemService::class);

        $item = $itemService->getItem();

        $this->assertInstanceOf(
            Item::class,
            $item
        );

        $this->assertSame(
            'other',
            $item->getValue()
        );
    }

    public function testBuildDependency(): void
    {
        $outerService = $this->container->build(OuterService::class);

        $this->assertInstanceOf(OuterService::class, $outerService);

        $innerService = $outerService->getInnerService();

        $this->assertInstanceOf(InnerService::class, $innerService);

        $this->assertNotSame(
            $innerService,
            $this->container->use(InnerService::class)
        );
    }

    public function testBuildNotInstantiable(): void
    {
        $this->expectException(ContainerNotFoundException::class);
        $this->expectExceptionMessageIs('Class `Closure` is not instantiable.');

        $this->container->build(Closure::class);
    }

    public function testBuildPositionalArguments(): void
    {
        $argumentService = $this->container->build(ArgumentService::class, [4, 5, 6]);

        $this->assertArraysAreIdentical(
            [4, 5, 6],
            $argumentService->getArguments()
        );
    }

    public function testBuildSharedDependency(): void
    {
        $this->container->singleton(InnerService::class);

        $outerService = $this->container->build(OuterService::class);

        $this->assertInstanceOf(OuterService::class, $outerService);

        $innerService = $outerService->getInnerService();

        $this->assertInstanceOf(InnerService::class, $innerService);

        $this->assertSame(
            $innerService,
            $this->container->use(InnerService::class)
        );
    }

    public function testCall(): void
    {
        $this->container->singleton(InnerService::class);

        $ran = false;
        $result = $this->container->call(function(Container $container, OuterService $outerService) use (&$ran): int {
            $ran = true;

            $this->assertSame($this->container, $container);

            $this->assertSame(
                $outerService->getInnerService(),
                $this->container->use(InnerService::class)
            );

            return 3;
        });

        $this->assertTrue($ran);

        $this->assertSame(3, $result);
    }

    public function testCallArguments(): void
    {
        $ran = false;
        $result = $this->container->call(static function(int $a = 1, int $b = 2, int $c = 3) use (&$ran): array {
            $ran = true;

            return [$a, $b, $c];
        }, ['a' => 4, 'b' => 5, 'c' => 6]);

        $this->assertTrue($ran);

        $this->assertArraysAreIdentical(
            [4, 5, 6],
            $result
        );
    }

    public function testCallArgumentsDependency(): void
    {
        $this->container->singleton(InnerService::class);

        $ran = false;
        $result = $this->container->call(function(Container $container, OuterService $outerService, int $a = 1, int $b = 2, int $c = 3) use (&$ran): array {
            $ran = true;

            $this->assertSame($this->container, $container);

            $this->assertSame(
                $outerService->getInnerService(),
                $this->container->use(InnerService::class)
            );

            return [$a, $b, $c];
        }, ['b' => 5]);

        $this->assertTrue($ran);

        $this->assertArraysAreIdentical(
            [1, 5, 3],
            $result
        );
    }

    public function testCallInvalidMethod(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessageIs('Method name must be a string.');

        // @phpstan-ignore argument.type
        $this->container->call([Service::class, 1]);
    }

    public function testCallInvalidTarget(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessageIs('Callable target must be a class-string or object.');

        // @phpstan-ignore argument.type
        $this->container->call([1, 'value']);
    }

    public function testCallPositionalArgumentsBeforeAutowiringAndDefaults(): void
    {
        $providedContainer = new Container(false);

        $result = $this->container->call(
            static fn(Container $container, int $a = 1, int $b = 2): array => [$container, $a, $b],
            [$providedContainer, 4, 'b' => 5]
        );

        $this->assertArraysAreIdentical(
            [$providedContainer, 4, 5],
            $result
        );
    }

    /**
     * @param Closure(): (array{0: class-string|object, 1?: string}|object|string) $factory
     */
    #[DataProvider('callRepresentationProvider')]
    public function testCallRepresentation(Closure $factory): void
    {
        $result = $this->container->call($factory(), ['a' => 1]);

        $this->assertSame(
            1,
            $result
        );
    }

    public function testDebug(): void
    {
        $this->assertContains(
            DebugTrait::class,
            class_uses(Container::class)
        );
    }

    public function testGet(): void
    {
        $service = $this->container->get(Service::class);

        $this->assertInstanceOf(
            Service::class,
            $service
        );
    }

    public function testGlobalInstance(): void
    {
        $container = Container::getInstance();

        $this->assertInstanceOf(
            Container::class,
            $container
        );

        $this->assertSame(
            $container,
            Container::getInstance()
        );

        $container = new Container();

        Container::setInstance($container);

        $this->assertSame(
            $container,
            Container::getInstance()
        );
    }

    public function testHas(): void
    {
        $this->assertTrue(
            $this->container->has(Service::class)
        );
    }

    public function testHasAlias(): void
    {
        $this->container->bind('service', Service::class);

        $this->assertTrue(
            $this->container->has('service')
        );
    }

    public function testHasAliasInstance(): void
    {
        $service = new Service();
        $this->container->instance('service', $service);
        $this->container->bind('alias', 'service');

        $this->assertTrue(
            $this->container->has('alias')
        );

        $this->assertSame(
            $service,
            $this->container->get('alias')
        );
    }

    public function testHasAliasSingleton(): void
    {
        $this->container->singleton(Service::class);
        $this->container->bind('service', Service::class);

        $this->assertTrue(
            $this->container->has('service')
        );

        $this->assertInstanceOf(
            Service::class,
            $this->container->get('service')
        );
    }

    public function testHasBoundFactory(): void
    {
        $this->container->bind('service', static fn(): Service => new Service());

        $this->assertTrue(
            $this->container->has('service')
        );
    }

    public function testHasCircularAlias(): void
    {
        $this->container->bind('service1', 'service2');
        $this->container->bind('service2', 'service1');

        $this->assertFalse(
            $this->container->has('service1')
        );
    }

    public function testHasInstance(): void
    {
        $service = new Service();
        $this->container->instance('service', $service);

        $this->assertTrue(
            $this->container->has('service')
        );

        $this->assertSame(
            $service,
            $this->container->get('service')
        );
    }

    public function testHasInvalid(): void
    {
        $this->assertFalse(
            $this->container->has('Invalid')
        );
    }

    public function testHasSelfBoundInvalid(): void
    {
        $this->container->bind('Invalid');

        $this->assertFalse(
            $this->container->has('Invalid')
        );
    }

    public function testHasSelfBoundNotInstantiable(): void
    {
        $this->container->singleton(Closure::class);

        $this->assertFalse(
            $this->container->has(Closure::class)
        );
    }

    public function testHasSingleton(): void
    {
        $this->container->singleton(Service::class);

        $this->assertTrue(
            $this->container->has(Service::class)
        );

        $this->assertInstanceOf(
            Service::class,
            $this->container->get(Service::class)
        );

        $this->assertTrue(
            $this->container->has(Service::class)
        );
    }

    public function testMacro(): void
    {
        $this->assertContains(
            MacroTrait::class,
            class_uses(Container::class)
        );
    }

    public function testReplaceInstance(): void
    {
        $service = new Service();
        $replacement = new Service();

        $this->container->singleton(
            Service::class,
            static fn(): Service => $service
        );
        $this->container->use(Service::class);

        $this->assertSame(
            $replacement,
            $this->container->replaceInstance(Service::class, $replacement)
        );

        $this->assertSame(
            $replacement,
            $this->container->use(Service::class)
        );

        $this->container->unset(Service::class);

        $this->assertSame(
            $service,
            $this->container->use(Service::class)
        );
    }

    public function testReplaceInstanceDependency(): void
    {
        $this->container
            ->singleton(InnerService::class)
            ->singleton(OuterService::class);

        $outerService = $this->container->use(OuterService::class);
        $replacement = new InnerService();

        $this->container->replaceInstance(InnerService::class, $replacement);

        $newOuterService = $this->container->use(OuterService::class);

        $this->assertNotSame($outerService, $newOuterService);
        $this->assertSame(
            $replacement,
            $newOuterService->getInnerService()
        );
    }

    public function testReplaceInstanceScoped(): void
    {
        $this->container->scoped(Service::class);

        $service = $this->container->use(Service::class);
        $replacement = new Service();

        $this->container->replaceInstance(Service::class, $replacement);
        $this->container->clearScoped();

        $newService = $this->container->use(Service::class);

        $this->assertNotSame($service, $newService);
        $this->assertNotSame($replacement, $newService);
    }

    public function testUse(): void
    {
        $service = $this->container->use(Service::class);

        $this->assertInstanceOf(Service::class, $service);

        $this->assertNotSame(
            $service,
            $this->container->use(Service::class)
        );
    }

    public function testUseAfterCircularAlias(): void
    {
        $this->container->bind('service1', 'service2');
        $this->container->bind('service2', 'service1');

        try {
            $this->container->use('service1');
            $this->fail('Expected the circular alias to be rejected.');
        } catch (ContainerException) {
        }

        $this->container->bind('service2', Service::class);

        $this->assertInstanceOf(
            Service::class,
            $this->container->use('service1')
        );
    }

    public function testUseAfterCircularDependency(): void
    {
        try {
            $this->container->use(CircularService::class);
            $this->fail('Expected the circular dependency to be rejected.');
        } catch (ContainerException) {
        }

        $dependency = $this->createStub(CircularDependency::class);
        $this->container->bind(CircularDependency::class, static fn(): CircularDependency => $dependency);

        $this->assertInstanceOf(
            CircularService::class,
            $this->container->call(static fn(CircularService $service): CircularService => $service)
        );
    }

    public function testUseAfterFactoryException(): void
    {
        $exception = new RuntimeException('Test exception.');
        $this->container->bind('service', static fn(): never => throw $exception);

        try {
            $this->container->use('service');
            $this->fail('Expected the factory to throw.');
        } catch (RuntimeException $e) {
            $this->assertSame($exception, $e);
        }

        $this->container->bind('service', Service::class);

        $this->assertInstanceOf(
            Service::class,
            $this->container->use('service')
        );
    }

    public function testUseCircularAlias(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessageIs('Alias `service1` is dependent on itself. (service1 > service2 > service1)');

        $this->container->bind('service1', 'service2');
        $this->container->bind('service2', 'service1');

        $this->container->use('service1');
    }

    public function testUseFactory(): void
    {
        $argumentService = new ArgumentService(7, 8, 9);

        $this->assertSame(
            $this->container,
            $this->container->bind(ArgumentService::class, static fn(): ArgumentService => $argumentService)
        );

        $this->assertSame(
            $argumentService,
            $this->container->use(ArgumentService::class)
        );
    }

    public function testUseInstance(): void
    {
        $argumentService = new ArgumentService(7, 8, 9);

        $this->assertSame(
            $argumentService,
            $this->container->instance(ArgumentService::class, $argumentService)
        );

        $this->assertSame(
            $argumentService,
            $this->container->use(ArgumentService::class)
        );
    }

    public function testUseScoped(): void
    {
        $this->assertSame(
            $this->container,
            $this->container->scoped(Service::class)
        );

        $service = $this->container->use(Service::class);

        $this->assertInstanceOf(Service::class, $service);

        $this->assertSame(
            $service,
            $this->container->use(Service::class)
        );

        $this->assertSame(
            $this->container,
            $this->container->clearScoped()
        );

        $this->assertNotSame(
            $service,
            $this->container->use(Service::class)
        );
    }

    public function testUseScopedAlias(): void
    {
        $this->container
            ->scoped(InnerService::class)
            ->singleton('service', InnerService::class);

        $innerService = $this->container->use('service');

        $this->assertSame(
            $innerService,
            $this->container->use(InnerService::class)
        );

        $this->container->clearScoped();

        $newInnerService = $this->container->use('service');

        $this->assertNotSame(
            $innerService,
            $newInnerService
        );

        $this->assertSame(
            $newInnerService,
            $this->container->use(InnerService::class)
        );
    }

    public function testUseScopedAliasCached(): void
    {
        $this->container
            ->scoped(InnerService::class)
            ->singleton('service', InnerService::class);

        $innerService = $this->container->use(InnerService::class);

        $this->assertSame(
            $innerService,
            $this->container->use('service')
        );

        $this->container->clearScoped();

        $newInnerService = $this->container->use('service');

        $this->assertNotSame(
            $innerService,
            $newInnerService
        );

        $this->assertSame(
            $newInnerService,
            $this->container->use(InnerService::class)
        );
    }

    public function testUseScopedDependency(): void
    {
        $this->assertSame(
            $this->container,
            $this->container->scoped(InnerService::class)
        );

        $this->assertSame(
            $this->container,
            $this->container->singleton(OuterService::class)
        );

        $outerService = $this->container->use(OuterService::class);

        $this->assertInstanceOf(OuterService::class, $outerService);

        $innerService = $outerService->getInnerService();

        $this->assertInstanceOf(InnerService::class, $innerService);

        $this->assertSame(
            $innerService,
            $this->container->use(InnerService::class)
        );

        $this->assertSame(
            $this->container,
            $this->container->clearScoped()
        );

        $this->assertNotSame(
            $outerService,
            $this->container->use(OuterService::class)
        );

        $this->assertNotSame(
            $innerService,
            $this->container->use(InnerService::class)
        );
    }

    public function testUseScopedTransientDependency(): void
    {
        $this->container
            ->scoped(InnerService::class)
            ->singleton(Service::class)
            ->singleton('service', static fn(OuterService $outerService): OuterService => $outerService);

        $service = $this->container->use(Service::class);
        $outerService = $this->container->use('service');

        $this->assertSame(
            $outerService,
            $this->container->use('service')
        );

        $this->assertSame(
            $outerService->getInnerService(),
            $this->container->use(InnerService::class)
        );

        $this->container->clearScoped();

        $newOuterService = $this->container->use('service');

        $this->assertNotSame(
            $outerService,
            $newOuterService
        );

        $this->assertNotSame(
            $outerService->getInnerService(),
            $newOuterService->getInnerService()
        );

        $this->assertSame(
            $newOuterService->getInnerService(),
            $this->container->use(InnerService::class)
        );

        $this->assertSame(
            $service,
            $this->container->use(Service::class)
        );
    }

    public function testUseScopedTransientDependencyCached(): void
    {
        $this->container
            ->scoped(InnerService::class)
            ->singleton('service', static fn(OuterService $outerService): OuterService => $outerService);

        $innerService = $this->container->use(InnerService::class);
        $outerService = $this->container->use('service');

        $this->assertSame(
            $innerService,
            $outerService->getInnerService()
        );

        $this->container->clearScoped();

        $newOuterService = $this->container->use('service');

        $this->assertNotSame(
            $outerService,
            $newOuterService
        );

        $this->assertNotSame(
            $innerService,
            $newOuterService->getInnerService()
        );

        $this->assertSame(
            $newOuterService->getInnerService(),
            $this->container->use(InnerService::class)
        );
    }

    public function testUseShared(): void
    {
        $this->assertSame(
            $this->container,
            $this->container->singleton(Service::class)
        );

        $service = $this->container->use(Service::class);

        $this->assertInstanceOf(Service::class, $service);

        $this->assertSame(
            $service,
            $this->container->use(Service::class)
        );
    }

    public function testUseUnscoped(): void
    {
        $this->assertSame(
            $this->container,
            $this->container->scoped(Service::class)
        );

        $service = $this->container->use(Service::class);

        $this->assertInstanceOf(Service::class, $service);

        $this->assertSame(
            $service,
            $this->container->use(Service::class)
        );

        $this->assertSame(
            $this->container,
            $this->container->unscoped(Service::class)
        );

        $this->assertSame(
            $this->container,
            $this->container->clearScoped()
        );

        $this->assertSame(
            $service,
            $this->container->use(Service::class)
        );
    }

    public function testUseUnset(): void
    {
        $this->assertSame(
            $this->container,
            $this->container->singleton(Service::class)
        );

        $service = $this->container->use(Service::class);

        $this->assertInstanceOf(Service::class, $service);

        $this->assertSame(
            $service,
            $this->container->use(Service::class)
        );

        $this->assertSame(
            $this->container,
            $this->container->unset(Service::class)
        );

        $this->assertNotSame(
            $service,
            $this->container->use(Service::class)
        );
    }

    #[Override]
    protected function setUp(): void
    {
        $this->container = new Container();
    }
}
