<?php
declare(strict_types=1);

namespace Tests\Mock\Commands;

use Fyre\Console\Command;
use Fyre\Utility\DateTime\Date;
use Override;

class TypeOptionsCommand extends Command
{
    #[Override]
    protected readonly array $options = [
        'test' => [
            'text' => 'What is the date?',
            'as' => 'date',
            'required' => true,
        ],
    ];

    public function run(Date $test): int
    {
        return $test->isSameDay(Date::now()) ?
            static::CODE_SUCCESS :
            static::CODE_ERROR;
    }
}
