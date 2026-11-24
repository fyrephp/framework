<?php
declare(strict_types=1);

namespace Fyre\Http\Session;

use Fyre\Core\Traits\DebugTrait;
use Fyre\Http\Session\Exceptions\SessionException;
use InvalidArgumentException;
use Override;
use SessionHandlerInterface;
use SessionIdInterface;
use SessionUpdateTimestampHandlerInterface;

use function array_replace_recursive;
use function preg_match;
use function session_create_id;

/**
 * Provides a base class for custom {@see SessionHandlerInterface} implementations.
 *
 * Supplies default configuration and a prefixed session key helper for {@see Session}.
 */
abstract class SessionHandler implements SessionHandlerInterface, SessionIdInterface, SessionUpdateTimestampHandlerInterface
{
    use DebugTrait;

    /**
     * @var array<string, mixed>
     */
    protected static array $defaults = [
        'prefix' => '',
        'expires' => 3600,
    ];

    /**
     * @var array<string, mixed>
     */
    protected array $config = [];

    /**
     * Constructs a SessionHandler.
     *
     * @param Session $session The Session.
     * @param array<string, mixed> $options The options for the handler.
     *
     * @throws InvalidArgumentException If a session handler option is not valid.
     */
    public function __construct(
        protected Session $session,
        array $options = []
    ) {
        $this->config = array_replace_recursive(self::$defaults, static::$defaults, $options);

        if ($this->config['expires'] <= 0) {
            throw new InvalidArgumentException('Session handler option `expires` must be greater than 0.');
        }
    }

    /**
     * Closes the session.
     *
     * @return bool Whether the session was closed.
     */
    #[Override]
    public function close(): bool
    {
        return true;
    }

    /**
     * Creates a session ID using PHP's session ID generator.
     *
     * @return string The session ID.
     *
     * @throws SessionException If the session ID cannot be generated.
     */
    #[Override]
    public function create_sid(): string
    {
        return session_create_id() ?: throw new SessionException('Failed to create a session ID.');
    }

    /**
     * Destroys the session.
     *
     * @param string $sessionId The session ID.
     * @return bool Whether the session was destroyed.
     */
    #[Override]
    abstract public function destroy(string $sessionId): bool;

    /**
     * Runs the session garbage collector.
     *
     * @param int $expires The maximum session lifetime.
     * @return false|int The number of sessions removed.
     */
    #[Override]
    abstract public function gc(int $expires): false|int;

    /**
     * Opens the session.
     *
     * @param string $path The session path.
     * @param string $name The session name.
     * @return bool Whether the session was opened.
     */
    #[Override]
    abstract public function open(string $path, string $name): bool;

    /**
     * Reads the session data.
     *
     * @param string $sessionId The session ID.
     * @return false|string The session data.
     */
    #[Override]
    abstract public function read(string $sessionId): false|string;

    /**
     * Refreshes the expiry of an existing session without rewriting its data.
     *
     * @param string $sessionId The session ID.
     * @param string $data The session data.
     * @return bool Whether the timestamp was updated.
     */
    #[Override]
    abstract public function updateTimestamp(string $sessionId, string $data): bool;

    /**
     * Checks whether a session ID exists in the backing store.
     *
     * @param string $sessionId The session ID.
     * @return bool Whether the session ID is valid.
     */
    #[Override]
    abstract public function validateId(string $sessionId): bool;

    /**
     * Writes the session data.
     *
     * @param string $sessionId The session ID.
     * @param string $data The session data.
     * @return bool Whether the data was written.
     */
    #[Override]
    abstract public function write(string $sessionId, string $data): bool;

    /**
     * Returns the session key.
     *
     * Prefixes the session id to create the underlying storage key.
     *
     * @param string $sessionId The session ID.
     * @return string The session key.
     */
    protected function prepareKey(string $sessionId): string
    {
        return $this->config['prefix'].$sessionId;
    }

    /**
     * Checks whether a session ID contains only supported characters.
     *
     * @param string $sessionId The session ID.
     * @return bool Whether the session ID is valid.
     */
    protected static function isValidSessionId(string $sessionId): bool
    {
        return preg_match('/\A[A-Za-z0-9,-]+\z/', $sessionId) === 1;
    }
}
