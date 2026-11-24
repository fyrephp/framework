<?php
declare(strict_types=1);

namespace Fyre\Log\Handlers;

use Fyre\Log\Logger;
use InvalidArgumentException;
use Override;
use Stringable;

use function fopen;
use function fwrite;
use function is_resource;
use function is_string;

use const PHP_EOL;

/**
 * Writes messages to a console stream.
 */
class ConsoleLogger extends Logger
{
    /**
     * @var array<string, mixed>
     */
    #[Override]
    protected static array $defaults = [
        'stream' => 'php://stderr',
    ];

    /**
     * @var resource|null
     */
    protected $handle;

    /**
     * Constructs a ConsoleLogger.
     *
     * @param array<string, mixed> $options The Logger options.
     *
     * @throws InvalidArgumentException If a console logger option is not valid.
     */
    public function __construct(array $options = [])
    {
        parent::__construct($options);

        if (!is_string($this->config['stream'])) {
            throw new InvalidArgumentException('Console logger option `stream` must be a string.');
        }

        $this->handle = @fopen($this->config['stream'], 'w') ?: null;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        if (!is_resource($this->handle)) {
            return;
        }

        $message = static::interpolate($message, $context) |> $this->format((string) $level, ?);

        @fwrite($this->handle, $message.PHP_EOL);
    }
}
