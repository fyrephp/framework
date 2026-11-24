<?php
declare(strict_types=1);

namespace Fyre\DB\Forge\Presets;

use Fyre\DB\Forge\Forge;
use Fyre\DB\Forge\Preset;
use Fyre\DB\Forge\Table;
use Fyre\DB\Types\DateTimeType;
use Fyre\DB\Types\IntegerType;
use Fyre\DB\Types\StringType;
use Override;

/**
 * Provides the migration history table definition.
 *
 * @internal
 */
class MigrationsPreset extends Preset
{
    #[Override]
    public const TABLE = 'fyre__migrations';

    /**
     * {@inheritDoc}
     */
    #[Override]
    protected static function build(Forge $forge): Table
    {
        return $forge
            ->build(static::TABLE)
            ->clear()
            ->addColumn('id', [
                'type' => IntegerType::class,
                'autoIncrement' => true,
            ])
            ->addColumn('batch', [
                'type' => IntegerType::class,
            ])
            ->addColumn('migration', [
                'type' => StringType::class,
            ])
            ->addColumn('timestamp', [
                'type' => DateTimeType::class,
                'default' => 'CURRENT_TIMESTAMP',
            ])
            ->setPrimaryKey('id')
            ->addIndex('fyre__migrations__batch', [
                'columns' => 'batch',
            ])
            ->addIndex('fyre__migrations__migration', [
                'columns' => 'migration',
                'unique' => true,
            ]);
    }
}
