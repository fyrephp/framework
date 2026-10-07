# Changelog

This file records user-visible changes to FyreFramework. Internal refactors, test-only changes, and other changes that do not affect users are omitted.

## 1.2.0 - 2026-10-07

### Added

- Add database connection aliases and `ConnectionHelper` methods for configuring and checking test connections.
- Add `QueueTestTrait`, `TestQueue`, and job assertions for capturing queued jobs without connecting to a broker or executing them.
- Add `CacheTestTrait` for isolated in-memory caches with configuration and enabled-state restoration.
- Add `IntegrationTestTrait::disableErrorRendering()` and `enableErrorRendering()` for testing exceptions through the existing error rendering event.
- Add Redis queue prefixes for isolating messages, reservations, failures, uniqueness keys, statistics, and queue discovery.

### Fixed

- Clear redirect URI ports before removing their hosts in `Auth::getLoginUrl()`.
- Return null cache handlers while caching is disabled, including when a real handler was loaded earlier, and preserve the loaded handler when caching is re-enabled.

### Changed

- Automatic fixtures now require configured `test` or `test_*` write connections. Configure connection aliases before application boot or resolving models.
- Load and clean up fixture tables through each model's write connection, restoring foreign-key checks when setup or cleanup fails.

## 1.1.0 - 2026-09-27

### Added

- Add ORM `firstOrFail()`, `saveOrFail()`, `saveManyOrFail()`, `deleteOrFail()`, and `deleteManyOrFail()` methods.
- Add `RecordNotFoundException` with HTTP 404 handling and `PersistenceFailedException` with `getEntity()` access to the failed entity.
- Add PHPStan return type inference for `model()`, `type()`, and `TypeParser::use()`.

### Fixed

- Hydrate missing joined `belongsTo` and `hasOne` relationships as `null` instead of empty entities, including nested contain paths.
- Reject non-finite floating-point values when parsing decimals.
- Correct `Rule::in()` documentation and PHPDoc to include boolean, integer, and floating-point values alongside strings.

### Changed

- Container dependency resolution now matches unmatched named arguments by parameter type, in supplied order, before autowiring. This can change which supplied value is injected.
- Describe decimal values as `numeric-string` in PHPDoc and generated entity property annotations.

## 1.0.1 - 2026-09-20

### Fixed

- Correct placeholder spacing in generated model and entity source files.
- Improve many-to-many relationship inference for junction tables with additional columns, self-references, and non-standard source binding keys.
- Reuse compatible junction `BelongsTo` relationships so many-to-many associations honor configured target binding keys.

### Changed

- Relationship inference warns and omits conflicting aliases or unsupported non-primary target binding keys for manual configuration.
- Many-to-many associations reject existing junction relationships with incompatible types, foreign keys, or target models.

## 1.0.0 - 2026-09-13

### Added

- Initial public release.
