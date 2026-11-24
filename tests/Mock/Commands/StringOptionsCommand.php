<?php
declare(strict_types=1);

namespace Tests\Mock\Commands;

use Fyre\Console\Command;
use Override;

class StringOptionsCommand extends Command
{
    #[Override]
    protected readonly array $options = [
        'value' => 'Please enter a value',
    ];

    public function run(string $value): int
    {
        return $value === 'value' ?
            static::CODE_SUCCESS :
            static::CODE_ERROR;
    }
}
