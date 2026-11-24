<?php
declare(strict_types=1);

namespace Tests\Mock\Commands;

use Fyre\Console\Command;
use Override;

class OptionsCommand extends Command
{
    #[Override]
    protected readonly array $options = [
        'value' => [
            'help' => 'Value to use.',
            'text' => 'Which do you want?',
            'values' => [
                'a',
                'b',
                'c',
            ],
            'required' => true,
            'default' => 'a',
        ],
    ];

    public function run(string $value): int
    {
        return $value === 'a' ?
            static::CODE_SUCCESS :
            static::CODE_ERROR;
    }
}
