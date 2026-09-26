# Saving Data

Use model-driven saving when you want schema parsing, validation, relationship handling, and transactions around your writes.

For direct bulk updates without entities, use `updateAll()`.

## Table of Contents

- [Start here](#start-here)
- [Building entities from input](#building-entities-from-input)
  - [Creating empty entities](#creating-empty-entities)
  - [Building new entities](#building-new-entities)
  - [Patching existing entities](#patching-existing-entities)
- [Validation and rule sets](#validation-and-rule-sets)
- [Saving entities](#saving-entities)
  - [Saving one entity](#saving-one-entity)
  - [Saving many entities](#saving-many-entities)
  - [Throwing on failure](#throwing-on-failure)
  - [Saving related entities](#saving-related-entities)
  - [Primary key population](#primary-key-population)
  - [Rollback-aware field changes](#rollback-aware-field-changes)
  - [Handling errors](#handling-errors)
- [Bulk updates with `updateAll()`](#bulk-updates-with-updateall)
- [Events and hooks](#events-and-hooks)
- [Related](#related)

## Start here

Most save workflows look like this:

1. Build or patch entities with `newEntity()` or `patchEntity()`.
2. Check the entity for validation errors if validation is enabled.
3. Call `save()` or `saveMany()`.

If you need to update many rows and don’t need entity-level behavior, use `updateAll()` instead.

The examples use a model instance named `$Users`; see [Models](models.md) for model resolution and configuration.

## Building entities from input

Creating entities through a model ensures they have the correct source and participate in model-driven behavior such as schema parsing, guarding, validation, and relationship injection.

These workflows are controlled by a set of common flags:

- **Schema parsing** (`$parse`): when enabled, values are converted using the model schema types (and parse events can run).
- **Guarding** (`$guard` and `$accessible`): when enabled, only accessible fields are set from input.
- **Mutation hooks** (`$mutate`): controls whether entity setter hooks are applied.
- **Validation** (`$validate`): when enabled, the model validator validates the input and populates entity errors. See [Form Validators](../form/validators.md).
- **Relationship selection** (`$associated`): controls which nested relationships are injected and how they are configured. When omitted, every relationship defined directly on the current model is eligible.
- **Parse events** (`$events`): controls `ORM.beforeParse` and `ORM.afterParse` when schema parsing is enabled.
- **Entity state** (`$clean` and `$new`): optionally clean the resulting entity or explicitly set whether it represents a new record.

### Creating empty entities

`newEmptyEntity()` creates a blank entity instance for the model. This is useful for “new record” workflows (for example, form defaults) without applying any input data.

```php
$user = $Users->newEmptyEntity();
```

### Building new entities

`newEntity()` and `newEntities()` build new entities from input data and apply the “user input” workflow (parsing, guarding, validation, and relationship injection) based on the flags you pass.

```php
$user = $Users->newEntity(
    [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
    ]
);
```

`newEntities()` accepts a list of input arrays and returns one entity for each entry in the same order.

### Patching existing entities

`patchEntity()` updates an existing entity in-place using the same workflow as `newEntity()` (and using the `update` validation type when validation is enabled).

```php
$user = $Users->get(10);
if ($user) {
    $Users->patchEntity($user, ['name' => 'Ada']);
}
```

`patchEntities()` applies a parallel list of input arrays by index. Entries without matching input are left unchanged.

## Validation and rule sets

Validation and rule sets both produce errors on entities, but they run at different times and serve different purposes:

- **Validation** runs when building or patching entities (when `$validate` is enabled). It validates user input shape and constraints (required fields, formats, lengths) and writes errors onto the entity. See [Form Validators](../form/validators.md).
- **Rule sets** run during `save()` / `saveMany()` (when `$checkRules` is enabled). They enforce model-level integrity that may require database context (for example uniqueness and foreign key existence) and can also write errors onto the entity. See [Rule Sets](rulesets.md).

If an entity is new or dirty, saving fails without attempting persistence when it or any nested entity already has errors. Clean existing entities are skipped before errors are checked.

## Saving entities

The same options control `save()` and `saveMany()`:

| Option | Purpose |
| --- | --- |
| `saveRelated` | save eligible related entities |
| `checkRules` | run the model rule set |
| `checkExists` | verify whether new entities with primary-key values already exist |
| `events` | dispatch save lifecycle events |
| `clean` | clean saved entities after commit |

These options default to `true`.

### Saving one entity

`save()` persists a single entity in a transaction.

Important behaviors:

- If the entity is not new and not dirty, `save()` returns `true` without issuing queries or checking errors.
- If the entity is new or dirty and it or any nested entity has errors, `save()` returns `false`.
- When enabled, existence checks run for “new” entities that already have primary key values.
- Changing primary key values on an existing entity throws an `OrmException` when saved. Use an explicit update query when a primary key change is required.
- When enabled, rule sets run as part of the save workflow. See [Rule Sets](rulesets.md).
- The save runs inside a transaction; failures roll back ORM-generated field changes on the entity and its related entities. Rolling back an enclosing transaction also undoes those changes, while rolling back a savepoint preserves changes made in the outer transaction.

```php
$user = $Users->newEntity(['email' => 'ada@example.com']);

if ($Users->save($user)) {
    // saved
}
```

### Saving many entities

`saveMany()` persists multiple entities as a single unit inside one transaction.

Important behaviors:

- Entities that are neither new nor dirty are filtered out before saving.
- If the filtered list is empty, `saveMany()` returns `true`.
- If any remaining entity or nested entity has errors, `saveMany()` returns `false`.
- Any failure rolls back all changes.

```php
$users = $Users->newEntities([
    ['email' => 'a@example.com'],
    ['email' => 'b@example.com'],
]);

$Users->saveMany($users);
```

### Throwing on failure

`saveOrFail()` and `saveManyOrFail()` accept the same arguments as `save()` and `saveMany()`. They return `true` on success and throw `Fyre\ORM\Exceptions\PersistenceFailedException` when the corresponding method returns `false`.

The exception extends `OrmException`. Its `getEntity()` method returns the entity that failed, including the specific failed entity in a batch.

```php
$Users->saveOrFail($user);
$Users->saveManyOrFail($users, saveRelated: false);
```

Validation, rule checks, callbacks, and transaction rollback behave as they do for the original methods. Existing exceptions propagate unchanged, and validation errors remain on the entities. An empty iterable passed to `saveManyOrFail()` succeeds.

### Saving related entities

When saving related entities is enabled (the default), a model saves relationships in two phases:

1. **Parents first**: relationships where the current entity stores the foreign key (for example `BelongsTo`).
2. **Children after**: relationships where the related entity (or a junction table) stores the link (for example `HasOne`, `HasMany`, `ManyToMany`).

In both phases, the ORM registers rollback callbacks when assigning relationship keys (for example foreign keys on children, or a belongs-to foreign key on the source entity). A rollback restores each field's previous value, or removes the field if it did not previously exist. Ordinary application edits are not reverted.

To build an entity with related data from input, provide nested data using relationship **property names** (by default, the underscored relationship name; singular for single relations, plural for multiple).

The `$associated` option is optional. When omitted, every relationship defined directly on the current model is eligible. Use it when you need to restrict those relationships or enable and configure deeper relationship paths.

```php
$article = $Articles->newEntity(
    [
        'title' => 'Saving graphs',

        // belongsTo('Users') typically maps to the `user` property.
        'user' => [
            'email' => 'author@example.com',
        ],

        // hasMany('Comments') typically maps to the `comments` property.
        'comments' => [
            ['body' => 'First!'],
            ['body' => 'Nice post'],
        ],
    ],
    associated: ['Users', 'Comments']
);

$Articles->save($article);
```

Many-to-many relationships can also include `_joinData` for junction table fields:

```php
$article = $Articles->newEntity(
    [
        'title' => 'Tagging',
        'tags' => [
            ['name' => 'orm', '_joinData' => ['weight' => 10]],
            ['name' => 'php'],
        ],
    ],
    associated: ['Tags']
);

$Articles->save($article);
```

To persist only the primary entity and ignore related entities, pass `saveRelated: false`:

```php
$Articles->save($article, saveRelated: false);
```

Only saved entities are cleaned after commit. Changes made after a save remain dirty, with the saved values as their originals. Skipped related entities retain their dirty and new state.

### Primary key population

When inserting a new entity, the ORM populates missing primary key values on the entity after the insert completes:

- if the insert returns a row containing primary key values, those values are applied
- if the table has an auto-increment primary key and it was not provided, the ORM uses the connection insert id

Primary keys populated during a save are restored or removed if the insert is rolled back, and the entity is marked new again. After a successful commit, entity cleaning (when enabled) marks the entity as not new.

```php
$user = $Users->newEntity(['email' => 'ada@example.com']);
$Users->save($user);

// Assuming `id` is the auto-increment primary key:
$id = $user->get('id');
```

### Rollback-aware field changes

Use `setTemporaryField()` inside a transaction, including in model save hooks, for field changes that should be undone on rollback:

```php
use Fyre\Utility\DateTime\DateTime;

$Users->getConnection()->transactional(static function() use ($Users, $user): bool {
    $Users->setTemporaryField($user, 'modified', DateTime::now());

    return $Users->save($user);
});
```

The helper registers undo work with the model's current transaction or savepoint. Calling it outside a transaction throws an `OrmException`. Validation errors remain available after rollback.

### Handling errors

Exceptions raised during saving or commit roll back the transaction and propagate to the caller; they are not converted to entity errors or `false`.

Validation and rule set errors are stored on the entity (and can include nested errors for related entities). Common patterns are:

- check validation errors after `newEntity()` / `patchEntity()`
- check rule set errors after a failed `save()` / `saveMany()`

```php
$user = $Users->newEntity(['email' => 'not-an-email']);

if ($user->hasErrors()) {
    $errors = $user->getErrors();
}

if (!$Users->save($user)) {
    $errors = $user->getErrors();
}
```

## Bulk updates with `updateAll()`

`updateAll(array $data, array|Closure|ConditionExpression|string $conditions): int` updates all rows matching the conditions and returns the number of rows affected.

It is implemented using an ORM `UpdateQuery` wrapper over the database query builder, so it:

- does not hydrate entities
- does not run model validation or rule sets
- does not run ORM save/parse events

```php
$affected = $Users->updateAll(
    ['active' => 0],
    ['Users.last_login <' => '2025-01-01']
);
```

## Events and hooks

Entity building and saving can dispatch ORM lifecycle events. The attribute-based listener system provides convenient hooks you can annotate on your model. See [ORM Events](events.md).

Saving-related attributes include:

- `#[BeforeParse]` and `#[AfterParse]` when parsing is enabled while building or patching entities.
- `#[BeforeRules]` and `#[AfterRules]` when rule checking is enabled during saves.
- `#[BeforeSave]`, `#[AfterSave]`, and `#[AfterSaveCommit]` around persistence and transaction commit.

Parse events run only when both `parse` and `events` are enabled. Disabling save events also suppresses `ORM.afterSaveCommit`.

## Related

- [Models](models.md)
- [Entities](entities.md)
- [Form Validators](../form/validators.md)
- [Rule Sets](rulesets.md)
- [ORM Events](events.md)
- [Database queries](../database/queries.md)
