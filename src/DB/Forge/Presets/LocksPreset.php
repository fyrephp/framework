<?php
declare(strict_types=1);

namespace Fyre\DB\Forge\Presets;

use Fyre\DB\Forge\Forge;
use Fyre\DB\Forge\Preset;
use Fyre\DB\Forge\Table;
use Fyre\DB\Types\DateTimeType;
use Fyre\DB\Types\StringType;
use Override;

/**
 * Provides the framework lock table definition.
 *
 * @internal
 */
class LocksPreset extends Preset
{
    public const NAME_LENGTH = 255;

    #[Override]
    public const TABLE = 'fyre__locks';

    /**
     * {@inheritDoc}
     */
    #[Override]
    protected static function build(Forge $forge): Table
    {
        return $forge
            ->build(static::TABLE)
            ->clear()
            ->addColumn('name', [
                'type' => StringType::class,
                'length' => static::NAME_LENGTH,
            ])
            ->addColumn('owner', [
                'type' => StringType::class,
                'length' => 32,
            ])
            ->addColumn('expires', [
                'type' => DateTimeType::class,
            ])
            ->setPrimaryKey('name')
            ->addIndex('fyre__locks__expires', [
                'columns' => 'expires',
            ]);
    }
}
