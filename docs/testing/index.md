# Testing

Use the testing layer when you want PHPUnit helpers for framework-powered code.

The section covers the base `TestCase`, fixtures, in-process HTTP and console testing, outbound client mocks, captured mail and log assertions, and a few small timing utilities.

## Table of Contents

- [Testing workflow](#testing-workflow)
- [Installation](#installation)
- [Testing overview](#testing-overview)
- [Pages in this section](#pages-in-this-section)

## Testing workflow

Start with [`TestCase`](test-case.md) for the framework-aware PHPUnit base class. Add [Fixtures](fixtures.md) only for database state, and use the focused integration helpers for the boundary under test: [HTTP](integration.md), [outbound HTTP](http-client.md), [console](console.md), [mail](mail.md), or [logging](logging.md).

[Timers](timers.md) and [Benchmark](benchmark.md) measure code; they do not assert a performance threshold automatically.

## Installation

The base test case, assertion traits, and constraints use PHPUnit. Add it to your application as a development dependency:

```bash
composer require --dev phpunit/phpunit:^13
```

The PHPStan extensions and PHP-CS-Fixer config are separate opt-in integrations. Install the matching tool only when you use that integration:

```bash
composer require --dev phpstan/phpstan:^2.1
composer require --dev friendsofphp/php-cs-fixer:^3.91
```

These packages are development dependencies of FyreFramework itself, but Composer does not install a dependency's `require-dev` packages for consumers.

Register the model return type extensions in your PHPStan configuration to infer concrete model classes from `model('Users')` and `ModelRegistry::use('Users')`:

```neon
services:
    -
        class: Fyre\TestSuite\PhpStan\Extensions\ModelFunctionReturnTypeExtension
        tags:
            - phpstan.broker.dynamicFunctionReturnTypeExtension
    -
        class: Fyre\TestSuite\PhpStan\Extensions\ModelRegistryUseReturnTypeExtension
        tags:
            - phpstan.broker.dynamicMethodReturnTypeExtension
```

Both extensions default to the `App\Models` namespace. Configure their `modelNamespaces` argument for other namespaces, or `modelNamespacesOverrides` for per-class overrides. Unknown aliases and non-constant strings fall back to `Fyre\ORM\Model`.

Register the database type extensions to infer concrete classes from `type('decimal')` and `TypeParser::use('decimal')`, including the `numeric-string|null` return type of `DecimalType::parse()`:

```neon
services:
    -
        class: Fyre\TestSuite\PhpStan\Extensions\TypeFunctionReturnTypeExtension
        tags:
            - phpstan.broker.dynamicFunctionReturnTypeExtension
    -
        class: Fyre\TestSuite\PhpStan\Extensions\TypeParserUseReturnTypeExtension
        tags:
            - phpstan.broker.dynamicMethodReturnTypeExtension
```

Both extensions use the default `TypeParser` mappings and aliases. Unknown literal names resolve to `StringType`, matching the runtime fallback; non-constant names retain `Type`. Calling `type()` or `type(null)` returns `TypeParser`.

If your application calls `TypeParser::map()`, configure the same mappings on both extensions using their `typeMap` argument:

```neon
arguments:
    typeMap:
        money: App\DB\Types\MoneyType
        decimal: App\DB\Types\CustomDecimalType
```

These mappings extend or replace the defaults. Runtime calls to `map()` are not tracked automatically.

## Testing overview

Choose the tool by what the test needs:

| Tool | Purpose |
| --- | --- |
| `TestCase` | framework-aware PHPUnit base class and automatic fixture lifecycle |
| Assertion traits | assertions over captured HTTP responses, console output, mail, and logs |
| Constraints | lower-level PHPUnit constraints for use with `assertThat()` |
| Fixtures | repeatable database rows loaded before a test and removed afterward |
| Integration helpers | in-process application requests and outbound HTTP client mocks |
| `Timer` | elapsed time for named phases that run once |
| `Benchmark` | repeated execution of named callbacks with time and memory results |

Timers and benchmarks report measurements; they are not assertions and do not decide whether a test passes. Compare their results explicitly when a test needs a performance threshold.

## Pages in this section

- [Test configuration](configuration.md) - isolate database connections, queues, cache, and error handling
- [`TestCase`](test-case.md) - base PHPUnit test case for framework-powered tests
- [Constraints](constraints.md) - lower-level PHPUnit constraints behind the higher-level helpers
- [Fixtures](fixtures.md) - define and load repeatable database data
- [Integration Testing](integration.md) - send in-process HTTP requests and assert on responses
- [HTTP Client Testing](http-client.md) - mock outbound `Client` calls
- [Console Testing](console.md) - run commands and assert on captured output
- [Email Testing](mail.md) - capture sent email and assert on recipients, subject, body, and attachments
- [Log Testing](logging.md) - capture log output with in-memory handlers
- [Timers](timers.md) - measure named phases with lightweight timers
- [Benchmark](benchmark.md) - compare named callbacks in-process
