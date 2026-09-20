# Changelog

This file records user-visible changes to FyreFramework. Internal refactors, test-only changes, and other changes that do not affect users are omitted.

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
