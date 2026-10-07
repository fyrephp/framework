# Fixtures

Fixtures provide repeatable database rows for tests. Each fixture targets a model, defines rows, and optionally allows nested association data.

Use fixtures through [`TestCase`](test-case.md) for automatic setup and cleanup. Resolve them through `FixtureRegistry` when a test needs manual control.

## Table of Contents

- [Define a fixture](#define-a-fixture)
- [Load fixtures in a test](#load-fixtures-in-a-test)
- [Discover fixtures](#discover-fixtures)
- [Load associated data](#load-associated-data)
- [Generate fixtures](#generate-fixtures)
- [Method guide](#method-guide)
  - [`FixtureRegistry`](#fixtureregistry)
  - [`Fixture`](#fixture)
- [Behavior notes](#behavior-notes)
- [Related](#related)

## Define a fixture

Extend `Fyre\TestSuite\Fixture\Fixture` and set the protected `$data` property:

```php
namespace Tests\Fixtures;

use Fyre\TestSuite\Fixture\Fixture;
use Override;

final class ItemsFixture extends Fixture
{
    #[Override]
    protected iterable $data = [
        [
            'id' => 1,
            'name' => 'First item',
        ],
        [
            'id' => 2,
            'name' => 'Second item',
        ],
    ];
}
```

The model alias defaults to the fixture's short class name without `Fixture`, so `ItemsFixture` uses the `Items` model. Set `protected string $classAlias` when the names differ.

`run()` creates each entity with `guard: false` and `validate: false`, then saves it with `checkExists: false` and `checkRules: false`. A failed save throws a `RuntimeException` identifying the row and model.

## Load fixtures in a test

Set the `$fixtures` property once on the test class to load those aliases before every test:

```php
use Fyre\TestSuite\TestCase;
use Override;

final class ItemsTableTest extends TestCase
{
    #[Override]
    protected array $fixtures = ['Items'];

    public function testFindsFixtureRows(): void
    {
        $items = model('Items')
            ->find()
            ->orderBy(['id' => 'ASC'])
            ->all();

        $this->assertSame(
            ['First item', 'Second item'],
            $items->extract('name')->toArray()
        );
    }
}
```

Automatic fixtures require configured `test` or `test_*` write connections. Add test connection aliases before resolving models; see [Test configuration](configuration.md).

`TestCase` disables foreign-key checks on each affected write connection while loading data and again while truncating affected tables after the test. Checks are re-enabled even if setup or cleanup fails.

For manual loading, resolve the registry and run the fixture:

```php
use Fyre\TestSuite\Fixture\FixtureRegistry;

$fixtures = app(FixtureRegistry::class);

$fixtures->use('Items')->run();
```

Manual `run()` calls do not arrange automatic cleanup; the caller owns that lifecycle.

## Discover fixtures

The shared `FixtureRegistry` searches `Tests\Fixtures` by default. Additional namespaces are searched in registration order. For alias `Items` and namespace `Tests\Fixtures`, the registry looks for `Tests\Fixtures\ItemsFixture` and uses the first class that extends `Fixture`.

Namespaces are trimmed, normalized to a trailing `\`, and deduplicated. Pass aliases without the `Fixture` suffix; `use('ItemsFixture')` would look for `ItemsFixtureFixture`.

`use($alias)` caches one fixture instance per alias. Use `build($alias)` for an uncached instance or `unload($alias)` before the next `use()` call when constructor dependencies must be resolved again.

## Load associated data

Fixtures ignore nested relationship data by default. Set `$associated` to the relationships that may be built:

```php
final class ItemsFixture extends Fixture
{
    #[Override]
    protected array|string|null $associated = 'Comments';

    #[Override]
    protected iterable $data = [
        [
            'name' => 'First item',
            'comments' => [
                [
                    'body' => 'First comment',
                ],
            ],
        ],
    ];
}
```

`getTables()` follows that association configuration and returns every table affected by setup or cleanup, including `ManyToMany` junction tables.

## Generate fixtures

Generate an empty fixture with `make:fixture`:

```bash
app make:fixture Items
```

Add `--data` to populate it from existing model rows. Rows are ordered by primary key, database values are converted through schema types, and `--limit` defaults to `10`:

```bash
app make:fixture Items --data --limit=25
```

## Method guide

The setup above uses the shared registry and the `ItemsFixture` definition. The methods below describe the remaining management and inspection API without repeating that setup.

### `FixtureRegistry`

| Method | Behavior |
| --- | --- |
| `addNamespace($namespace)` | add a normalized namespace unless it is already registered |
| `use($alias)` | return the cached fixture for an alias, building it on first use |
| `build($alias)` | build a new uncached fixture; throw when no matching class exists |
| `isLoaded($alias)` | check whether `use()` has cached the alias |
| `unload($alias)` | remove one cached fixture and return the registry |
| `clear()` | remove every cached fixture and registered namespace |

### `Fixture`

| Method | Behavior |
| --- | --- |
| `run()` | save every row returned by `data()` and throw on the first failure |
| `data()` | return the configured iterable dataset |
| `associated()` | return the allowed association configuration |
| `getClassAlias()` | return the explicit alias or derive it from the fixture class name |
| `getModel()` | resolve and cache the fixture model |
| `getTables()` | return the model, associated, and junction tables affected by the fixture |
| `getTablesByConnection()` | group affected tables by their models' write connections |

## Behavior notes

- Namespace order controls which fixture wins when several namespaces contain the same alias.
- `FixtureRegistry::use()` shares instances by alias; `build()` does not cache its result.
- `FixtureRegistry::clear()` removes namespaces as well as fixture instances.
- Fixture saves bypass guards, validation, existence checks, and application rules, but database constraints still apply.
- Nested relationship rows are ignored unless `$associated` allows them.
- Automatic `TestCase` cleanup truncates every table returned by `getTablesByConnection()` through its write connection.

## Related

- [Testing](index.md)
- [`TestCase`](test-case.md)
- [Integration Testing](integration.md)
- [Models](../orm/models.md)
