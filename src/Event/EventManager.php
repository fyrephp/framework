<?php
declare(strict_types=1);

namespace Fyre\Event;

use Closure;
use Fyre\Cache\CacheManager;
use Fyre\Cache\Cacher;
use Fyre\Core\Traits\DebugTrait;
use Fyre\Event\Attributes\On;
use Override;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\EventDispatcher\StoppableEventInterface;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;

use function array_column;
use function array_splice;
use function array_values;
use function count;
use function str_replace;
use function uasort;

/**
 * Manages event listeners and dispatches events.
 *
 * Listener callbacks are executed in ascending priority order (lower values run first).
 * When a parent EventManager is configured, events are dispatched to the parent after local
 * listeners unless propagation has been stopped.
 */
class EventManager implements EventDispatcherInterface, ListenerProviderInterface
{
    use DebugTrait;

    public const PRIORITY_HIGH = 10;

    public const PRIORITY_LOW = 200;

    public const PRIORITY_NORMAL = 100;

    protected const CACHE_KEY = '_events';

    /**
     * @var array<string, array<string, mixed>[]>
     */
    protected array $events = [];

    /**
     * @var array<class-string<EventListenerInterface>, array<string, mixed>[]>
     */
    protected array $listenerEvents = [];

    /**
     * @var array<string, Closure[]>
     */
    protected array $sortedEvents = [];

    /**
     * Constructs an EventManager.
     *
     * @param CacheManager $cacheManager The CacheManager.
     * @param EventManager|null $parentEventManager The parent EventManager.
     */
    public function __construct(
        protected CacheManager $cacheManager,
        protected EventManager|null $parentEventManager = null
    ) {}

    /**
     * Adds an EventListener.
     *
     * Methods annotated with {@see On} will be registered as listeners using the listener event prefix.
     *
     * @param EventListenerInterface $listener The EventListener.
     * @return static The EventManager instance.
     */
    public function addListener(EventListenerInterface $listener): static
    {
        $events = $this->loadEvents($listener);

        foreach ($events as $data) {
            $this->on($data['name'], $this->resolveListenerCallback($listener, $data['callback']), $data['priority']);
        }

        return $this;
    }

    /**
     * Clears all events.
     */
    public function clear(): void
    {
        $this->events = [];
        $this->listenerEvents = [];
        $this->sortedEvents = [];
    }

    /**
     * {@inheritDoc}
     *
     * Note: When the event is an {@see Event}, each listener receives the Event instance as the first
     * argument, followed by the event data values. If the Event result is set to `false`, propagation
     * is stopped.
     *
     * For other objects, each listener receives the event object as the only argument.
     *
     * @template T of object
     *
     * @param T $event The event.
     * @return T The event.
     */
    #[Override]
    public function dispatch(object $event): object
    {
        $listeners = $this->getListenersForEvent($event);

        if ($event instanceof Event) {
            foreach ($listeners as $listener) {
                if ($event->isPropagationStopped()) {
                    break;
                }

                $listener($event, ...($event->getData() |> array_values(...)));

                if ($event->getResult() === false) {
                    $event->stopPropagation();
                }
            }
        } else {
            foreach ($listeners as $listener) {
                if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                    break;
                }

                $listener($event);
            }
        }

        if ($this->parentEventManager && !($event instanceof StoppableEventInterface && $event->isPropagationStopped())) {
            return $this->parentEventManager->dispatch($event);
        }

        return $event;
    }

    /**
     * Returns the Cacher.
     *
     * @return Cacher|null The Cacher.
     */
    public function getCache(): Cacher|null
    {
        return $this->cacheManager->hasConfig(static::CACHE_KEY) ?
            $this->cacheManager->use(static::CACHE_KEY) :
            null;
    }

    /**
     * {@inheritDoc}
     *
     * Note: The returned listener list is cached per event name until listeners are modified.
     *
     * @param object $event The event.
     * @return Closure[] The listener callbacks.
     */
    #[Override]
    public function getListenersForEvent(object $event): array
    {
        $name = $event instanceof Event ?
            $event->getName() :
            $event::class;

        if (!isset($this->events[$name])) {
            return [];
        }

        if (isset($this->sortedEvents[$name])) {
            return $this->sortedEvents[$name];
        }

        $listeners = $this->events[$name] ?? [];

        uasort(
            $listeners,
            static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']
        );

        return $this->sortedEvents[$name] = array_column($listeners, 'callback');
    }

    /**
     * Checks whether an event exists.
     *
     * @param string $name The event name.
     * @return bool Whether the event exists.
     */
    public function has(string $name): bool
    {
        return isset($this->events[$name]);
    }

    /**
     * Removes an event.
     *
     * @param string $name The event name.
     * @param callable|null $callback The callback.
     * @return static The EventManager instance.
     */
    public function off(string $name, callable|null $callback = null): static
    {
        if (!isset($this->events[$name])) {
            return $this;
        }

        unset($this->sortedEvents[$name]);

        if ($callback === null) {
            unset($this->events[$name]);

            return $this;
        }

        $callback = $callback(...);

        for ($i = count($this->events[$name]) - 1; $i >= 0; $i--) {
            if ($this->events[$name][$i]['callback'] != $callback) {
                continue;
            }

            array_splice($this->events[$name], $i, 1);
        }

        if ($this->events[$name] === []) {
            unset($this->events[$name]);
        }

        return $this;
    }

    /**
     * Adds an event.
     *
     * @param string $name The event name.
     * @param callable $callback The callback.
     * @param int|null $priority The event priority.
     * @return static The EventManager instance.
     */
    public function on(string $name, callable $callback, int|null $priority = null): static
    {
        $this->events[$name] ??= [];

        $this->events[$name][] = [
            'callback' => $callback(...),
            'priority' => $priority ?? static::PRIORITY_NORMAL,
        ];

        unset($this->sortedEvents[$name]);

        return $this;
    }

    /**
     * Removes an EventListener.
     *
     * @param EventListenerInterface $listener The EventListener.
     * @return static The EventManager instance.
     */
    public function removeListener(EventListenerInterface $listener): static
    {
        $events = $this->loadEvents($listener);

        foreach ($events as $data) {
            $this->off($data['name'], $this->resolveListenerCallback($listener, $data['callback']));
        }

        return $this;
    }

    /**
     * Triggers an event.
     *
     * Note: The triggered Event has a null subject.
     *
     * @param string $name The event name.
     * @param mixed ...$args The event arguments.
     * @return Event The event.
     */
    public function trigger(string $name, mixed ...$args): Event
    {
        return (new Event($name, data: $args)) |> $this->dispatch(...);
    }

    /**
     * Loads events for an EventListener.
     *
     * @param EventListenerInterface $listener The EventListener.
     * @return array<string, mixed>[] The events.
     */
    protected function loadEvents(EventListenerInterface $listener): array
    {
        if ($this->parentEventManager) {
            return $this->parentEventManager->loadEvents($listener);
        }

        if (isset($this->listenerEvents[$listener::class])) {
            return $this->listenerEvents[$listener::class];
        }

        $cache = $this->getCache();

        if (!$cache) {
            return $this->listenerEvents[$listener::class] = static::findEvents($listener);
        }

        $cacheKey = str_replace('\\', '.', $listener::class);

        return $this->listenerEvents[$listener::class] = $cache->remember(
            $cacheKey,
            static::findEvents($listener, ...)
        );
    }

    /**
     * Resolves a callback closure for a listener method.
     *
     * @param EventListenerInterface $listener The EventListener.
     * @param string $method The method name.
     * @return Closure The callback closure.
     */
    protected function resolveListenerCallback(EventListenerInterface $listener, string $method): Closure
    {
        return (new ReflectionMethod($listener, $method))->getClosure($listener);
    }

    /**
     * Finds events for an EventListener.
     *
     * @param EventListenerInterface $listener The EventListener.
     * @return array<string, mixed>[] The events.
     */
    protected static function findEvents(EventListenerInterface $listener): array
    {
        $reflection = new ReflectionClass($listener);
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED);

        $events = [];

        foreach ($methods as $method) {
            $attributes = $method->getAttributes(On::class, ReflectionAttribute::IS_INSTANCEOF);

            foreach ($attributes as $attribute) {
                $instance = $attribute->newInstance();

                $events[] = [
                    'name' => $instance->getName(),
                    'priority' => $instance->getPriority() ?? static::PRIORITY_NORMAL,
                    'callback' => $method->getName(),
                ];
            }
        }

        return $events;
    }
}
