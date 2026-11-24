<?php
declare(strict_types=1);

namespace Tests\Mock\Commands;

use Fyre\Console\Command;
use Override;

class OptionalOptionsCommand extends Command
{
    #[Override]
    protected readonly array $options = [
        'choice' => [
            'values' => [
                'a' => 'Option A',
                'b' => 'Option B',
            ],
            'default' => 'a',
        ],
        'enabled' => [
            'as' => 'boolean',
        ],
        'value' => [
            'default' => 'value',
        ],
    ];

    public function run(string $choice, bool $enabled, string $value): int
    {
        return $choice === 'a' && !$enabled && $value === 'value' ?
            static::CODE_SUCCESS :
            static::CODE_ERROR;
    }
}
