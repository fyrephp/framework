<?php
declare(strict_types=1);

namespace Fyre\Console;

use Fyre\Core\Traits\DebugTrait;

/**
 * Provides a base class for console commands.
 *
 * Commands are discovered by {@see CommandRunner} via reflection and are expected to implement a `run()` method.
 */
abstract class Command
{
    use DebugTrait;

    public const CODE_ERROR = 1;

    public const CODE_SUCCESS = 0;

    protected readonly string|null $alias = null;

    protected readonly string $description = '';

    protected Console $io;

    /**
     * @var array<string, array<string, mixed>|string>
     */
    protected readonly array $options = [];

    /**
     * Constructs a Command.
     *
     * @param Console $io The Console.
     */
    public function __construct(Console $io)
    {
        $this->io = $io;
    }
}
