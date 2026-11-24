<?php
declare(strict_types=1);

namespace Fyre\TestSuite\Constraint\Console;

use Override;
use PHPUnit\Framework\Constraint\Constraint;

use function implode;
use function sprintf;
use function str_contains;

use const PHP_EOL;

/**
 * PHPUnit constraint asserting console output contains a value.
 *
 * Note: The compared value is expected to be an array of output lines.
 */
class ContentsContains extends Constraint
{
    /**
     * Constructs a ContentsContains.
     *
     * @param string $needle The expected string.
     * @param string $output The output type.
     */
    public function __construct(
        protected string $needle,
        protected string $output
    ) {}

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toString(): string
    {
        return sprintf(
            'contains "%s"',
            $this->needle
        );
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    protected function failureDescription(mixed $other): string
    {
        return sprintf(
            '%s %s',
            $this->output,
            $this->toString()
        );
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    protected function matches(mixed $other): bool
    {
        return implode(PHP_EOL, $other) |> str_contains(?, $this->needle);
    }
}
