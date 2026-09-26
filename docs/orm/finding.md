# Finding Data

Use the ORM finding APIs when you want model-aware queries and entity results.

## Table of Contents

- [Start here](#start-here)
- [Finding overview](#finding-overview)
- [Finding many records](#finding-many-records)
  - [Building a `SelectQuery`](#building-a-selectquery)
  - [Common query options](#common-query-options)
  - [Getting entities vs raw rows](#getting-entities-vs-raw-rows)
  - [Loading related data with `contain()`](#loading-related-data-with-contain)
  - [Filtering by relationships](#filtering-by-relationships)
  - [Getting a subset of results](#getting-a-subset-of-results)
  - [Paginating entities](#paginating-entities)
- [Finding one record](#finding-one-record)
- [Working with `Result`](#working-with-result)
  - [Buffering vs streaming](#buffering-vs-streaming)
  - [Result metadata and cleanup](#result-metadata-and-cleanup)
  - [Forwarded collection methods](#forwarded-collection-methods)
- [Find events](#find-events)
- [Related](#related)

## Start here

Use the ORM finding APIs when you want to:

- query through a model instead of writing table names directly
- return entities instead of raw database rows
- load related data with `contain()`
- filter through relationship-aware joins like `matching()`

The examples use a model instance named `$Users`; see [Models](models.md) for model resolution and configuration.

## Finding overview

- `Model::find()` returns an ORM-aware `SelectQuery`.
- `SelectQuery::all()` and `SelectQuery::getResult()` return a `Fyre\ORM\Result` that maps rows into entities.
- `Model::get()` is a convenience method for primary-key lookups and returns `Entity|null`.
- `SelectQuery::firstOrFail()` returns the first matching entity or throws a `RecordNotFoundException`.

For the underlying query builder syntax (conditions, joins, ordering, grouping, and SQL compilation), see [Database queries](../database/queries.md). For entity field access and `_matchingData`, see [Entities](entities.md).

## Finding many records

### Building a `SelectQuery`

`find()` returns a `SelectQuery` you can continue to refine using query-builder methods:

```php
$result = $Users->find()
    ->where(['Users.id >' => 10])
    ->orderBy('Users.id DESC')
    ->all();

foreach ($result as $user) {
    $id = $user->get('id');
    $name = $user->get('name');
}
```

### Common query options

`Model::find()` (and `Model::get()`) accept a set of optional parameters that map directly to `SelectQuery` builder methods:

- `fields` → `select()`
- `contain` → `contain()`
- `join` → `join()`
- `conditions` → `where()`
- `orderBy` → `orderBy()`
- `groupBy` → `groupBy()`
- `having` → `having()`
- `limit` → `limit()`
- `offset` → `offset()`
- `epilog` → `epilog()`

The `connectionType` option selects the model connection (`Model::READ` by default), `alias` changes the query alias, and `autoFields` controls whether model fields are selected automatically.

Named arguments work well here:

```php
$users = $Users->find(
    conditions: ['Users.id >' => 10],
    orderBy: 'Users.id DESC',
    limit: 50
)->toArray();
```

Condition callbacks receive the current query and can use the same expression API as database queries:

```php
use Fyre\DB\Expressions\ConditionExpression;
use Fyre\DB\Query;

$users = $Users->find(
    conditions: static fn(Query $query): ConditionExpression => $query->expr()
        ->gte('Users.created', $since)
        ->isNull('Users.deleted')
)->toArray();
```

See [Query expressions](../database/expressions.md) for the available condition methods.

Calling `sql()` prepares the current query before compilation, including automatic fields and contain/join configuration. By default, the query is reset to its pre-prepared state afterward.

### Getting entities vs raw rows

When you want hydrated entities, prefer `all()` / `getResult()` / `toArray()` over calling `execute()` directly:

- `SelectQuery::execute()` returns a `Fyre\DB\ResultSet` of raw rows (arrays).
- `SelectQuery::all()` returns a `Fyre\ORM\Result` that maps rows into entities (and can apply `contain()` and `_matchingData` hydration).

### Loading related data with `contain()`

`contain()` tells the ORM to load relationships alongside the primary rows. Relationship names come from your model relationship definitions (see [ORM Relationships](relationships.md)).

Contain supports:

- Nested paths (for example `Posts.Comments`)
- Array forms for per-relationship options

Successive `contain()` calls merge their configuration. Pass `overwrite: true` to replace it instead.

How related data is loaded depends on the contain strategy:

- **Default strategies** (`select`, `subquery`, `cte`): related data is loaded using additional queries.
- **Join loading** (`strategy => 'join'`): the query is expanded with joins and joined columns are hydrated into relationship properties on the entity.

```php
$users = $Users->find()
    ->contain('Addresses')
    ->where(['Users.id IN' => [1, 2, 3]])
    ->toArray();

$property = $Users->getRelationship('Addresses')->getProperty();
$addresses = $users[0]->get($property);
```

### Filtering by relationships

When filtering based on related rows, use relationship join helpers:

- `matching()` performs an `INNER` join and hydrates matching data under `_matchingData`.
- `notMatching()` excludes rows that have a match (using a `NOT EXISTS (...)` subquery).
- `leftJoinWith()` / `innerJoinWith()` join relationship tables without hydrating `_matchingData`.

```php
$result = $Users->find()
    ->matching('Posts', ['Posts.title LIKE' => '%SQL%'])
    ->all();

foreach ($result as $user) {
    $matching = $user->get('_matchingData');
    $post = $matching['Posts'] ?? null;
}
```

### Getting a subset of results

Use `count()` and `first()` when you only need a subset:

- `count()` counts the current query (including any `LIMIT`/`OFFSET`) by wrapping it as a subquery and removing `ORDER BY`.
- `first()` returns the first entity (and applies `LIMIT 1` when results are not already loaded).

```php
$total = $Users->find(conditions: ['Users.id >' => 10])->count();
$first = $Users->find(conditions: ['Users.id >' => 10])->first();
```

### Paginating entities

ORM select queries inherit all three database pagination strategies and return hydrated entities as their page items:

```php
$page = $Users->find()
    ->where(['Users.active' => true])
    ->orderBy(['Users.id' => 'ASC'])
    ->paginate(page: 2, perPage: 25);

$pageWithTotal = $Users->find()
    ->where(['Users.active' => true])
    ->orderBy(['Users.id' => 'ASC'])
    ->paginateWithTotal(page: 2, perPage: 25);

$cursorPage = $Users->find()
    ->where(['Users.active' => true])
    ->orderBy(['Users.created' => 'DESC', 'Users.id' => 'DESC'])
    ->paginateByCursor(cursor: $cursor, perPage: 25);
```

Use `paginate()` to avoid a count query, `paginateWithTotal()` for exact totals, and `paginateByCursor()` for ordered keyset traversal.

Cursor-ordered fields are selected internally, including fields from joined `contain()` relationships, and must contain non-null scalar database values. Selected aliases are supported when they resolve to fields or value expressions. `DISTINCT` queries require all ordered fields to be explicitly selected, and set-operation queries are not supported. The final ordered field should normally be the model primary key. See [Database query pagination](../database/queries.md#pagination) for the result APIs and cursor contract.

## Finding one record

`Model::get()` retrieves a single entity by the model primary key(s). It builds a `find()` query, adds primary key conditions, and returns `first()`.

If the record does not exist, `get()` returns `null`.

After the primary-key value, `get()` accepts the same named query options as `find()`.

```php
$user = $Users->get(10, contain: 'Addresses');
```

If the model uses a composite primary key, pass an array of values in primary-key order:

```php
$membership = $Memberships->get([10, 25]);
```

Every primary-key value is required. Missing, `null`, empty-string, or empty-array values throw an `OrmException`.

Use `firstOrFail()` when a query must return an entity:

```php
$user = $Users->find()
    ->where(['Users.email' => 'ada@example.com'])
    ->firstOrFail();
```

It behaves like `first()`, including applying `LIMIT 1` when results have not already been loaded, but throws `Fyre\ORM\Exceptions\RecordNotFoundException` instead of returning `null`. This exception extends `OrmException` and produces a 404 response when handled by the HTTP error handler. Query execution errors propagate unchanged.

## Working with `Result`

`Fyre\ORM\Result` decorates a database `ResultSet` and turns each row into an entity (including contained data and `_matchingData` when applicable).

`all()` and `getResult()` return the same cached `Result` until the query is modified.

You can iterate the result directly:

```php
foreach ($Users->find()->all() as $user) {
    $name = $user->get('name');
}
```

### Buffering vs streaming

By default, results are buffered in memory so you can iterate multiple times without re-executing the query.

If you disable buffering on the query, iteration becomes streaming:

- entities are produced one-by-one
- the underlying cursor is freed once exhausted (or if you call `free()`)
- when using non-join contain paths, related data can be loaded incrementally during iteration

```php
$result = $Users->find(contain: 'Addresses')
    ->disableBuffering()
    ->all();

foreach ($result as $user) {
    // ...
}
```

### Result metadata and cleanup

`Result` exposes a small set of direct helpers:

- `Result::columns()` and `Result::columnCount()` expose result-set metadata.
- `Result::getType($name)` returns the database type handler for a column (when available).
- `Result::free()` releases resources early and stops streaming iteration.
- `Result::fetch($index)` reads an entity at an index (but can advance the cursor when streaming).

### Forwarded collection methods

`Result` forwards unknown method calls to its underlying `Fyre\Utility\Collection` of entities. This lets you use collection helpers without manually converting to an array.

## Find events

When events are enabled for a query (the default), `SelectQuery` triggers:

- `ORM.beforeFind` once when the query is prepared (for example when executing, counting, or generating SQL)
- `ORM.afterFind` when the query result is first wrapped in a `Result`

To learn how to listen using `#[BeforeFind]` / `#[AfterFind]` attributes or event-manager listeners, see [ORM Events](events.md).

## Related

- [Models](models.md)
- [Entities](entities.md)
- [ORM Relationships](relationships.md)
- [ORM Events](events.md)
- [Database queries](../database/queries.md)
- [Query expressions](../database/expressions.md)
