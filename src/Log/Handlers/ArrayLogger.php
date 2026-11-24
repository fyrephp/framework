<?php
declare(strict_types=1);

namespace Fyre\Log\Handlers;

use Fyre\Log\Logger;
use Override;
use Stringable;

/**
 * Stores messages in memory for later inspection.
 */
class ArrayLogger extends Logger
{
    /**
     * @var string[]
     */
    protected array $content = [];

    /**
     * Clears the log content.
     */
    public function clear(): void
    {
        $this->content = [];
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->content[] = $this->interpolate($message, $context)
            |> $this->format((string) $level, ?, false);
    }

    /**
     * Reads the log content.
     *
     * @return string[] The log content.
     */
    public function read(): array
    {
        return $this->content;
    }
}
