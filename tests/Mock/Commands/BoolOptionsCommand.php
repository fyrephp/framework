<?php
declare(strict_types=1);

namespace Tests\Mock\Commands;

use Fyre\Console\Command;
use Override;

class BoolOptionsCommand extends Command
{
    #[Override]
    protected readonly array $options = [
        'test' => [
            'text' => 'Do you agree?',
            'as' => 'boolean',
            'required' => true,
        ],
    ];

    public function run(bool $test): int
    {
        return $test === true ?
            static::CODE_SUCCESS :
            static::CODE_ERROR;
    }
}
