# Changelog

This file records user-visible changes to FyreFramework. Internal refactors, test-only changes, and other changes that do not affect users are omitted.

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
