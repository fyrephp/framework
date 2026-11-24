<?php
declare(strict_types=1);

namespace Tests\Mock\Commands;

use Fyre\Console\Command;
use Override;

class TestCommand extends Command
{
    #[Override]
    protected readonly string|null $alias = 'tester';

    #[Override]
    protected readonly string $description = 'This is a test command.';

    public function run(): void {}
}
