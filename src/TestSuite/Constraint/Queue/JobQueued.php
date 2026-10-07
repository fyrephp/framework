<?php
declare(strict_types=1);

namespace Fyre\TestSuite\Constraint\Queue;

use Fyre\Queue\Message;
use Override;
use PHPUnit\Framework\Constraint\Constraint;

use function array_any;
use function array_key_exists;
use function sprintf;

/**
 * PHPUnit constraint asserting a job was queued with matching arguments and options.
 */
class JobQueued extends Constraint
{
    /**
     * Constructs a JobQueued.
     *
     * @param class-string $className The job class.
     * @param array<string, mixed>|null $arguments The expected arguments, or null to accept any.
     * @param array<string, mixed> $options The expected message options.
     */
    public function __construct(
        protected string $className,
        protected array|null $arguments = null,
        protected array $options = []
    ) {}

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function toString(): string
    {
        return sprintf(
            'contains queued job `%s` with matching arguments and options',
            $this->className
        );
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    protected function matches(mixed $other): bool
    {
        return array_any($other, function(Message $message): bool {
            $config = $message->getConfig();

            if (
                $config['className'] !== $this->className ||
                ($this->arguments !== null && $config['arguments'] !== $this->arguments)
            ) {
                return false;
            }

            foreach ($this->options as $key => $value) {
                if (!array_key_exists($key, $config) || $config[$key] !== $value) {
                    return false;
                }
            }

            return true;
        });
    }
}
