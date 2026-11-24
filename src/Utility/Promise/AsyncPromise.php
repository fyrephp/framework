<?php
declare(strict_types=1);

namespace Fyre\Utility\Promise;

use Closure;
use Fyre\Utility\Promise\Exceptions\CancelledPromiseException;
use Override;
use ReflectionFunction;
use RuntimeException;
use Socket;
use Throwable;

use function count;
use function is_array;
use function pcntl_async_signals;
use function pcntl_fork;
use function pcntl_get_last_error;
use function pcntl_waitpid;
use function pcntl_wifstopped;
use function posix_get_last_error;
use function posix_kill;
use function posix_strerror;
use function serialize;
use function socket_close;
use function socket_create_pair;
use function socket_read;
use function socket_select;
use function socket_write;
use function sprintf;
use function strlen;
use function substr;
use function time;
use function unserialize;
use function usleep;

use const AF_UNIX;
use const PCNTL_EINTR;
use const SIGKILL;
use const SOCK_STREAM;
use const WNOHANG;
use const WUNTRACED;

/**
 * Provides an asynchronous promise implementation.
 *
 * The callback is executed in a forked child process and the result is sent back to the parent
 * over a socket. Values and rejection reasons must be serializable.
 *
 * Note: The callback must accept resolve/reject parameters; it cannot be a zero-argument callback.
 */
class AsyncPromise extends Promise
{
    protected static int $maxRunTime = 300;

    protected static int $waitTime = 100000;

    protected string $buffer = '';

    protected int|null $pid = null;

    protected Socket $socket;

    protected int $startTime;

    /**
     * Reaps the child process, terminating unfinished work without settling the Promise.
     */
    public function __destruct()
    {
        if ($this->pid === null) {
            return;
        }

        // Only waitable children belong to this process; forked copies must not signal them.
        if (pcntl_waitpid($this->pid, $status, WNOHANG) === 0 && posix_kill($this->pid, SIGKILL)) {
            while (pcntl_waitpid($this->pid, $status) === -1 && pcntl_get_last_error() === PCNTL_EINTR) {
            }
        }

        $this->pid = null;
    }

    /**
     * Cancels the pending Promise.
     *
     * If the promise has already settled, this method does nothing.
     *
     * @param string|null $message The message.
     *
     * @throws RuntimeException If the process cannot be killed.
     */
    public function cancel(string|null $message = null): void
    {
        if ($this->result || $this->pid === null) {
            return;
        }

        if (!posix_kill($this->pid, SIGKILL)) {
            $lastErrorString = posix_get_last_error() |> posix_strerror(...);

            throw new RuntimeException(sprintf(
                'Process `%d` could not be killed: %s',
                $this->pid,
                $lastErrorString
            ));
        }

        if (pcntl_waitpid($this->pid, $status) !== $this->pid) {
            throw new RuntimeException(sprintf(
                'Process `%d` could not be reaped.',
                $this->pid
            ));
        }

        $this->pid = null;

        socket_close($this->socket);
        $this->buffer = '';

        $result = Promise::reject(new CancelledPromiseException($message));

        $this->settle($result);
    }

    /**
     * Waits for the Promise to settle.
     *
     * This blocks the current process until the child process settles or is cancelled.
     */
    #[Override]
    public function wait(): void
    {
        while (!$this->poll()) {
            usleep(static::$waitTime);
        }
    }

    /**
     * {@inheritDoc}
     *
     * @throws RuntimeException If the socket pair cannot be created or the process cannot be forked.
     */
    #[Override]
    protected function call(Closure $callback): void
    {
        $sockets = [];
        if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets)) {
            throw new RuntimeException('Unable to create socket pair for async promise.');
        }
        [$parentSocket, $childSocket] = $sockets;

        pcntl_async_signals(true);

        $pid = pcntl_fork();

        if ($pid === -1) {
            socket_close($parentSocket);
            socket_close($childSocket);

            throw new RuntimeException('Unable to fork process for async promise.');
        }

        if ($pid === 0) {
            // child
            socket_close($childSocket);

            try {
                static::runChild($callback, $parentSocket);
            } finally {
                exit;
            }
        }

        // parent
        socket_close($parentSocket);

        $this->startTime = time();
        $this->pid = $pid;
        $this->socket = $childSocket;
    }

    /**
     * Polls the child process to determine if the Promise has settled.
     *
     * If the child process is still running and exceeds the maximum runtime, it
     * is cancelled.
     *
     * @return bool Whether the Promise has settled.
     *
     * @throws RuntimeException If the child process cannot be polled, or if the
     *                          child response cannot be read or decoded.
     */
    #[Override]
    protected function poll(): bool
    {
        if ($this->result) {
            return true;
        }

        if ($this->pid === null) {
            throw new RuntimeException('The child process has already been reaped.');
        }

        $processStatus = pcntl_waitpid($this->pid, $status, WNOHANG | WUNTRACED);

        if ($processStatus !== 0 && $processStatus !== $this->pid) {
            throw new RuntimeException(sprintf(
                'Process `%d` could not be polled.',
                $this->pid
            ));
        }

        if ($processStatus === $this->pid && !pcntl_wifstopped($status)) {
            $this->pid = null;
        }

        // Drain available data while the child is running so its writes cannot block on a full socket.
        do {
            $read = [$this->socket];
            $write = $except = [];
            $available = socket_select($read, $write, $except, 0);

            if ($available === false) {
                throw new RuntimeException('Unable to poll response from child process.');
            }

            if ($available === 0) {
                break;
            }

            $data = socket_read($this->socket, 4096);

            if ($data === false) {
                socket_close($this->socket);
                throw new RuntimeException('Unable to read response from child process.');
            }

            $this->buffer .= $data;
        } while ($data !== '');

        if ($processStatus === 0) {
            if ($this->startTime + static::$maxRunTime < time() || pcntl_wifstopped($status)) {
                $this->cancel();
            }

            return false;
        }

        socket_close($this->socket);

        $output = $this->buffer === '' ? null : unserialize($this->buffer);
        $this->buffer = '';

        if (!is_array($output) || count($output) !== 2) {
            throw new RuntimeException('Unable to read response from child process.');
        }

        [$reason, $value] = $output;

        $result = $reason ?
            Promise::reject($reason) :
            Promise::resolve($value);

        $this->settle($result);

        return true;
    }

    /**
     * Executes the Promise callback in the child process.
     *
     * @param Closure $callback The callback.
     * @param Socket $socket The socket.
     */
    protected static function runChild(Closure $callback, Socket $socket): void
    {
        $reflection = new ReflectionFunction($callback);
        $paramCount = $reflection->getNumberOfParameters();

        $settle = static function(Throwable|null $reason, mixed $value = null) use ($socket): void {
            $data = serialize([$reason, $value]);

            $length = strlen($data);
            $offset = 0;
            while ($offset < $length) {
                $written = socket_write($socket, substr($data, $offset));
                if ($written === false) {
                    break;
                }
                $offset += $written;
            }

            socket_close($socket);
        };

        try {
            if ($paramCount === 0) {
                $callback();
            } else {
                $callback(
                    static function(mixed $value = null) use (&$settle): void {
                        if (!$settle) {
                            return;
                        }

                        $settle(null, $value);
                        $settle = null;
                    },
                    static function(Throwable|null $reason = null) use (&$settle): void {
                        if (!$settle) {
                            return;
                        }

                        $settle($reason ?? new RuntimeException());
                        $settle = null;
                    }
                );
            }
        } catch (Throwable $e) {
            if ($settle !== null) {
                $settle($e);
            }
        }
    }
}
