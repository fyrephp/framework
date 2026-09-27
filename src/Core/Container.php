<?php
declare(strict_types=1);

namespace Fyre\Core;

use Closure;
use Fyre\Core\Exceptions\ContainerException;
use Fyre\Core\Exceptions\ContainerNotFoundException;
use Fyre\Core\Traits\DebugTrait;
use Fyre\Core\Traits\MacroTrait;
use Override;
use Psr\Container\ContainerInterface;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionFunction;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;

use function array_all;
use function array_any;
use function array_find_key;
use function array_key_exists;
use function array_key_last;
use function array_keys;
use function array_map;
use function array_merge;
use function array_pop;
use function array_search;
use function array_shift;
use function array_slice;
use function array_values;
use function assert;
use function class_exists;
use function explode;
use function get_debug_type;
use function implode;
use function in_array;
use function is_array;
use function is_callable;
use function is_float;
use function is_int;
use function is_iterable;
use function is_object;
use function is_string;
use function method_exists;
use function sprintf;
use function str_contains;

/**
 * Provides a dependency injection container.
 */
class Container implements ContainerInterface
{
    use DebugTrait;
    use MacroTrait;

    protected static Container|null $instance = null;

    /**
     * @var string[]
     */
    protected array $aliasStack = [];

    /**
     * @var array<string, array{Closure|string, bool}>
     */
    protected array $bindings = [];

    /**
     * @var class-string[]
     */
    protected array $buildStack = [];

    /**
     * @var array<class-string<ContextualAttribute<mixed>>, Closure>
     */
    protected array $contextualAttributes = [];

    /**
     * @var array<string, array<string, true>>
     */
    protected array $dependencyMap = [];

    /**
     * @var object[][]
     */
    protected array $dependencyStack = [];

    /**
     * @var array<string, object>
     */
    protected array $instances = [];

    /**
     * @var array<string, true>
     */
    protected array $scoped = [];

    /**
     * Returns the global instance.
     *
     * @return Container The Container instance.
     */
    public static function getInstance(): Container
    {
        return static::$instance ??= new self();
    }

    /**
     * Sets the global instance.
     *
     * @param Container $instance The Container.
     */
    public static function setInstance(Container $instance): void
    {
        static::$instance = $instance;
    }

    /**
     * Constructs a Container.
     *
     * @param bool $bind Whether to bind the instance to itself.
     */
    public function __construct(bool $bind = true)
    {
        if ($bind) {
            $this->instance(self::class, $this);
        }
    }

    /**
     * Binds an alias to a factory Closure or class name.
     *
     * @param string $alias The alias.
     * @param Closure|string|null $factory The factory Closure or class name.
     * @param bool $shared Whether the instance of this alias should be shared.
     * @param bool $scoped Whether the instance of this alias is scoped.
     * @return static The Container instance.
     */
    public function bind(string $alias, Closure|string|null $factory = null, bool $shared = false, bool $scoped = false): static
    {
        $this->unset($alias);
        $this->unscoped($alias);

        $factory ??= $alias;

        $this->bindings[$alias] = [$factory, $shared];

        if ($scoped) {
            $this->scoped[$alias] = true;
        }

        return $this;
    }

    /**
     * Binds a contextual attribute to a handler.
     *
     * The handler will be executed via {@see Container::call()} with an argument named
     * `attribute` containing the attribute instance.
     *
     * To receive the attribute instance, the handler should accept a parameter named
     * `$attribute` (type-hinted to the attribute class if desired).
     *
     * @param class-string<ContextualAttribute<mixed>> $attribute The attribute FQCN.
     * @param Closure $handler The handler.
     * @return static The Container instance.
     */
    public function bindAttribute(string $attribute, Closure $handler): static
    {
        $this->contextualAttributes[$attribute] = $handler;

        return $this;
    }

    /**
     * Builds a class instance, injecting dependencies as required.
     *
     * @template T of object
     *
     * @param class-string<T> $className The class name.
     * @param array<mixed> $arguments The constructor arguments.
     * @return T The class instance.
     *
     * @throws ContainerNotFoundException If the class is not valid.
     */
    public function build(string $className, array $arguments = []): mixed
    {
        if (!class_exists($className)) {
            throw new ContainerNotFoundException(sprintf(
                'Class `%s` does not exist.',
                $className
            ));
        }

        $reflection = new ReflectionClass($className);

        if (!$reflection->isInstantiable()) {
            throw new ContainerNotFoundException(sprintf(
                'Class `%s` is not instantiable.',
                $className
            ));
        }

        $this->buildStack[] = $className;

        try {
            $constructor = $reflection->getConstructor();

            if (!$constructor) {
                return new $className();
            }

            $parameters = $constructor->getParameters();

            $arguments = $this->resolveDependencies($parameters, $arguments);

            $this->addDependenciesToStack($arguments);

            return $reflection->newInstanceArgs($arguments);
        } finally {
            array_pop($this->buildStack);
        }
    }

    /**
     * Executes a callable using resolved dependencies.
     *
     * @param array{0: class-string|object, 1?: string}|object|string $callable The callable.
     * @param array<mixed> $arguments The function arguments.
     * @return mixed The return value of the callable.
     *
     * @throws ContainerException If a dependency cannot be resolved.
     */
    public function call(array|object|string $callable, array $arguments = []): mixed
    {
        if (is_string($callable) && str_contains($callable, '::')) {
            $callable = explode('::', $callable, 2);
        }

        if (is_array($callable)) {
            $target = $callable[0];
            $method = $callable[1] ?? '__invoke';

            if (!is_string($method)) {
                throw new ContainerException('Method name must be a string.');
            }

            if (!is_string($target) && !is_object($target)) {
                throw new ContainerException('Callable target must be a class-string or object.');
            }

            $reflection = new ReflectionMethod($target, $method);

            if ($reflection->isStatic()) {
                $target = null;
            } else if (is_string($target)) {
                $target = $this->use($target);
            }

            $arguments = $this->resolveDependencies($reflection->getParameters(), $arguments);

            $this->addDependenciesToStack($arguments);

            return $reflection->invokeArgs($target, $arguments);
        }

        if (is_string($callable) && class_exists($callable) && method_exists($callable, '__invoke')) {
            $callable = $this->use($callable);
        }

        assert(is_callable($callable));

        $reflection = new ReflectionFunction($callable(...));

        $arguments = $this->resolveDependencies($reflection->getParameters(), $arguments);

        $this->addDependenciesToStack($arguments);

        return $reflection->invokeArgs($arguments);
    }

    /**
     * Clears all scoped instances (but keep scoped bindings), including any dependents.
     *
     * @return static The Container instance.
     */
    public function clearScoped(): static
    {
        foreach ($this->scoped as $alias => $v) {
            $this->unset($alias);
        }

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function get(string $alias): mixed
    {
        return $this->use($alias);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function has(string $alias): bool
    {
        $seen = [];

        $current = $alias;

        while (true) {
            if (isset($this->instances[$current])) {
                return true;
            }

            if (isset($seen[$current])) {
                return false;
            }

            $seen[$current] = true;

            if (
                !isset($this->bindings[$current]) ||
                $this->bindings[$current][0] === $current
            ) {
                return class_exists($current) && new ReflectionClass($current)->isInstantiable();
            }

            [$factory] = $this->bindings[$current];

            if (!is_string($factory)) {
                return true;
            }

            $current = $factory;
        }
    }

    /**
     * Binds an alias to a class instance.
     *
     * @template T
     *
     * @param string $alias The alias.
     * @param T $instance The class instance.
     * @return T The instance.
     */
    public function instance(string $alias, mixed $instance): mixed
    {
        $this->unset($alias);

        unset($this->bindings[$alias]);

        $this->instances[$alias] = $instance;

        return $instance;
    }

    /**
     * Replaces the current instance while preserving its binding.
     *
     * Any dependents of the previous instance are unset. If the alias is scoped,
     * {@see Container::clearScoped()} will remove the replacement and the preserved
     * binding will be used on the next resolution.
     *
     * @template T
     *
     * @param string $alias The alias.
     * @param T $instance The instance.
     * @return T The instance.
     */
    public function replaceInstance(string $alias, mixed $instance): mixed
    {
        if (($this->instances[$alias] ?? null) === $instance) {
            return $instance;
        }

        $this->unset($alias);

        $this->instances[$alias] = $instance;

        return $instance;
    }

    /**
     * Binds an alias to a factory Closure or class name as a reusable scoped instance.
     *
     * @param string $alias The alias.
     * @param Closure|string|null $factory The factory Closure or class name.
     * @return static The Container instance.
     */
    public function scoped(string $alias, Closure|string|null $factory = null): static
    {
        return $this->bind($alias, $factory, true, true);
    }

    /**
     * Binds an alias to a factory Closure or class name as a reusable instance.
     *
     * @param string $alias The alias.
     * @param Closure|string|null $factory The factory Closure or class name.
     * @return static The Container instance.
     */
    public function singleton(string $alias, Closure|string|null $factory = null): static
    {
        return $this->bind($alias, $factory, true);
    }

    /**
     * Removes an alias from the scoped instances.
     *
     * @param string $alias The alias.
     * @return static The Container instance.
     */
    public function unscoped(string $alias): static
    {
        unset($this->scoped[$alias]);

        return $this;
    }

    /**
     * Removes an instance and optionally any dependents.
     *
     * Dependent tracking is identity-based and only includes dependencies that are already
     * container-managed shared instances at resolution time (i.e. present in {@see $instances}).
     *
     * @param string $alias The alias.
     * @param bool $unsetDependents Whether to unset dependents.
     * @return static The Container instance.
     */
    public function unset(string $alias, bool $unsetDependents = true): static
    {
        $dependents = $unsetDependents ?
            array_keys($this->dependencyMap[$alias] ?? []) :
            [];

        unset($this->dependencyMap[$alias], $this->instances[$alias]);

        foreach ($dependents as $dependent) {
            $this->unset((string) $dependent);
        }

        return $this;
    }

    /**
     * Resolves and returns an instance for the given alias.
     *
     * If the alias is bound as shared (singleton or scoped), the instance will only be cached
     * when invoked without manual arguments.
     *
     * @param string $alias The alias.
     * @param array<mixed> $arguments The constructor arguments.
     * @return mixed The class instance.
     *
     * @throws ContainerException If a dependency cannot be resolved.
     */
    public function use(string $alias, array $arguments = []): mixed
    {
        if (isset($this->instances[$alias]) && $arguments === []) {
            $this->addDependenciesToStack([$this->instances[$alias]]);

            return $this->instances[$alias];
        }

        if (in_array($alias, $this->aliasStack, true)) {
            $cycle = [...$this->aliasStack, $alias];

            throw new ContainerException(sprintf(
                'Alias `%s` is dependent on itself. (%s)',
                $alias,
                implode(' > ', $cycle)
            ));
        }

        $this->aliasStack[] = $alias;
        $this->dependencyStack[] = [];

        try {
            [$factory, $shared] = $this->bindings[$alias] ?? [$alias, false];

            if (is_string($factory)) {
                if ($factory === $alias) {
                    assert(class_exists($factory));

                    /** @var class-string $className */
                    $className = $factory;

                    $instance = $this->build($className, $arguments);
                } else {
                    $instance = $this->use($factory, $arguments);
                }
            } else {
                $instance = $this->call($factory, $arguments);
            }
        } finally {
            $dependencies = array_pop($this->dependencyStack);
            array_pop($this->aliasStack);
        }

        $this->addDependenciesToStack($dependencies);

        if (!$shared || $arguments !== []) {
            return $instance;
        }

        foreach ($dependencies as $dependency) {
            $key = array_search($dependency, $this->instances, true);

            if ($key !== false) {
                $this->dependencyMap[$key] ??= [];
                $this->dependencyMap[$key][$alias] = true;
            }
        }

        $this->instances[$alias] = $instance;
        $this->addDependenciesToStack([$instance]);

        return $instance;
    }

    /**
     * Adds any dependencies to the stack.
     *
     * Only dependencies that are already container-managed shared instances will be recorded.
     *
     * @param mixed[] $arguments The resolved dependencies.
     */
    protected function addDependenciesToStack(array $arguments): void
    {
        $lastIndex = array_key_last($this->dependencyStack);

        if ($lastIndex === null) {
            return;
        }

        foreach ($arguments as $argument) {
            if (!is_object($argument) || !in_array($argument, $this->instances, true)) {
                continue;
            }

            $this->dependencyStack[$lastIndex][] = $argument;
        }
    }

    /**
     * Resolves dependencies from parameters.
     *
     * Named and positional arguments take precedence over contextual attributes. Unmatched named
     * arguments are matched by type in provided order before autowiring. Any remaining arguments are appended positionally.
     *
     * @param ReflectionParameter[] $parameters The function parameters.
     * @param array<mixed> $arguments The provided arguments.
     * @return mixed[] The resolved dependencies (in invocation order).
     *
     * @throws ContainerException If a dependency cannot be resolved.
     */
    protected function resolveDependencies(array $parameters, array $arguments): array
    {
        $dependencies = [];
        $positionalArguments = [];
        $unmatchedArguments = [];

        $paramNames = array_map(
            static fn(ReflectionParameter $parameter): string => $parameter->getName(),
            $parameters
        );

        foreach ($arguments as $key => $argument) {
            if (is_int($key)) {
                $positionalArguments[] = $argument;
            } else if (!in_array($key, $paramNames, true)) {
                $unmatchedArguments[$key] = $argument;
            } else {
                continue;
            }

            unset($arguments[$key]);
        }

        foreach ($parameters as $parameter) {
            $paramName = $parameter->getName();

            if (array_key_exists($paramName, $arguments)) {
                $dependencies[] = $arguments[$paramName];
                unset($arguments[$paramName]);

                continue;
            }

            if ($positionalArguments !== []) {
                $dependencies[] = array_shift($positionalArguments);

                continue;
            }

            $attributes = $parameter->getAttributes(ContextualAttribute::class, ReflectionAttribute::IS_INSTANCEOF);
            $attribute = $attributes[0] ?? null;

            if ($attribute) {
                $instance = $attribute->newInstance();
                $name = $attribute->getName();

                if (isset($this->contextualAttributes[$name])) {
                    $dependencies[] = $this->call($this->contextualAttributes[$name], ['attribute' => $instance]);
                } else {
                    $dependencies[] = $instance->resolve($this);
                }

                continue;
            }

            $paramType = $parameter->getType();
            $e = null;

            if ($paramType !== null) {
                $matchedKey = array_find_key(
                    $unmatchedArguments,
                    static fn(mixed $argument): bool => static::matchesType($argument, $paramType, $parameter->getDeclaringClass())
                );

                if ($matchedKey !== null) {
                    $dependencies[] = $unmatchedArguments[$matchedKey];
                    unset($unmatchedArguments[$matchedKey]);

                    continue;
                }
            }

            if ($paramType instanceof ReflectionNamedType && !$paramType->isBuiltin()) {
                try {
                    $className = static::resolveClassName($paramType->getName(), $parameter->getDeclaringClass());

                    if (!$className) {
                        throw new ContainerException(sprintf(
                            'Dependency `%s` could not be resolved.',
                            $paramName
                        ));
                    }

                    $index = array_search($className, $this->buildStack, true);

                    if ($index === false) {
                        $dependencies[] = $this->use($className);

                        continue;
                    }

                    $dependents = array_map(
                        static fn(string $dependent): string => '`'.$dependent.'`',
                        array_slice($this->buildStack, (int) $index)
                    );

                    throw new ContainerException(sprintf(
                        'Class `%s` is dependent on itself. (%s)',
                        $className,
                        implode(' > ', $dependents)
                    ));
                } catch (ContainerException $e) {
                }
            }

            if ($parameter->isDefaultValueAvailable()) {
                $dependencies[] = $parameter->getDefaultValue();
            } else if ($parameter->allowsNull()) {
                $dependencies[] = null;
            } else if (!$parameter->isVariadic()) {
                throw $e ?? new ContainerException(sprintf(
                    'Dependency `%s` could not be resolved.',
                    $paramName
                ));
            }
        }

        $arguments = array_merge($positionalArguments, array_values($unmatchedArguments));

        return array_merge($dependencies, $arguments);
    }

    /**
     * Checks whether a supplied argument matches a parameter type.
     *
     * @param mixed $value The supplied argument.
     * @param ReflectionType $type The parameter type.
     * @param ReflectionClass<object>|null $declaringClass The class declaring the parameter.
     * @return bool Whether the argument matches the type.
     */
    protected static function matchesType(mixed $value, ReflectionType $type, ReflectionClass|null $declaringClass): bool
    {
        if ($value === null) {
            return $type->allowsNull();
        }

        if ($type instanceof ReflectionUnionType) {
            return array_any(
                $type->getTypes(),
                static fn(ReflectionType $member): bool => static::matchesType($value, $member, $declaringClass)
            );
        }

        if ($type instanceof ReflectionIntersectionType) {
            return array_all(
                $type->getTypes(),
                static fn(ReflectionType $member): bool => static::matchesType($value, $member, $declaringClass)
            );
        }

        if (!($type instanceof ReflectionNamedType)) {
            return false;
        }

        if ($type->isBuiltin()) {
            return match ($type->getName()) {
                'callable' => is_callable($value),
                'false' => $value === false,
                'float' => is_float($value) || is_int($value),
                'iterable' => is_iterable($value),
                'mixed' => true,
                'object' => is_object($value),
                'true' => $value === true,
                default => get_debug_type($value) === $type->getName(),
            };
        }

        $className = static::resolveClassName($type->getName(), $declaringClass);

        return $className !== null && $value instanceof $className;
    }

    /**
     * Resolves a parameter type name to a class name.
     *
     * @param string $typeName The parameter type name.
     * @param ReflectionClass<object>|null $declaringClass The class declaring the parameter.
     * @return string|null The resolved class name.
     */
    protected static function resolveClassName(string $typeName, ReflectionClass|null $declaringClass): string|null
    {
        $className = match ($typeName) {
            'parent' => $declaringClass?->getParentClass() ?: null,
            'self' => $declaringClass,
            default => $typeName,
        };

        if ($className instanceof ReflectionClass) {
            $className = $className->getName();
        }

        return $className;
    }
}
