<?php
declare(strict_types=1);

namespace Fyre\TestSuite\Constraint\Console;

use Override;
use PHPUnit\Framework\Constraint\Constraint;

use function implode;
use function preg_match;
use function sprintf;

use const PHP_EOL;

/**
 * PHPUnit constraint asserting console output matches a regular expression.
 *
 * Note: The compared value is expected to be an array of output lines.
 */
class ContentsRegExp extends Constraint
{
    /**
     * Constructs a ContentsRegExp.
     *
     * @param string $pattern The expected pattern.
     * @param string $output The output type.
     */
    public function __construct(
        protected string $pattern,
        protected string $output
    ) {}

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toString(): string
    {
        return sprintf(
            'matches the pattern `%s`',
            $this->pattern
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
        return (implode(PHP_EOL, $other) |> preg_match($this->pattern, ?)) === 1;
    }
}
