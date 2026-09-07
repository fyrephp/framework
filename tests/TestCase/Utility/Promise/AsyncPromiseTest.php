<?php
declare(strict_types=1);

namespace Tests\TestCase\Utility\Promise;

use Closure;
use Exception;
use Fyre\Utility\Promise\AsyncPromise;
use Fyre\Utility\Promise\Exceptions\CancelledPromiseException;
use Fyre\Utility\Promise\Promise;
use Fyre\Utility\Promise\PromiseInterface;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

use function gc_collect_cycles;
use function pcntl_fork;
use function pcntl_waitid;
use function pcntl_waitpid;
use function pcntl_wexitstatus;
use function posix_kill;
use function sleep;
use function socket_close;
use function socket_create_pair;
use function socket_read;
use function socket_write;
use function str_repeat;
use function time;
use function unserialize;

use const AF_UNIX;
use const P_PID;
use const SIGKILL;
use const SOCK_STREAM;
use const WEXITED;
use const WNOHANG;
use const WNOWAIT;

#[RequiresPhpExtension('pcntl')]
#[RequiresPhpExtension('posix')]
#[RequiresPhpExtension('sockets')]
final class AsyncPromiseTest extends TestCase
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function childCallbackProvider(): array
    {
        return [
            'resolve' => [
                'callback' => static function(Closure $resolve): void {
                    $resolve('test');
                },
                'expected' => [
                    'reason' => null,
                    'value' => 'test',
                ],
            ],
            'reject' => [
                'callback' => static function(Closure $resolve, Closure $reject): void {
                    $reject(new Exception('test'));
                },
                'expected' => [
                    'reason' => [Exception::class, 'test'],
                    'value' => null,
                ],
            ],
            'reject without reason' => [
                'callback' => static function(Closure $resolve, Closure $reject): void {
                    $reject();
                },
                'expected' => [
                    'reason' => [RuntimeException::class, ''],
                    'value' => null,
                ],
            ],
            'throw' => [
                'callback' => static function(): never {
                    throw new Exception('test');
                },
                'expected' => [
                    'reason' => [Exception::class, 'test'],
                    'value' => null,
                ],
            ],
            'settle once' => [
                'callback' => static function(Closure $resolve, Closure $reject): void {
                    $resolve(1);
                    $resolve(2);
                    $reject(new Exception());
                },
                'expected' => [
                    'reason' => null,
                    'value' => 1,
                ],
            ],
        ];
    }

    /**
     * @return array<string, array{method: string, expected: array<int>|int}>
     */
    public static function combinationProvider(): array
    {
        return [
            'all' => ['method' => 'all', 'expected' => [42]],
            'any' => ['method' => 'any', 'expected' => 42],
            'race' => ['method' => 'race', 'expected' => 42],
        ];
    }

    /**
     * @return array<string, array{bool, bool}>
     */
    public static function destructProvider(): array
    {
        return [
            'running' => [false, false],
            'completed' => [true, false],
            'running race participant' => [false, true],
            'completed race participant' => [true, true],
        ];
    }

    public function testAllPreservesOrder(): void
    {
        $sockets = [];

        $result = socket_create_pair(
            AF_UNIX,
            SOCK_STREAM,
            0,
            $sockets
        );

        $this->assertTrue($result);

        [$reader, $writer] = $sockets;

        $promise1 = new AsyncPromise(static function(Closure $resolve) use ($reader): void {
            socket_read($reader, 1);
            $resolve(1);
        });

        $promise2 = new AsyncPromise(static function(Closure $resolve) use ($writer): void {
            $resolve(2);
            socket_write($writer, '1');
        });

        try {
            Promise::all([
                'first' => $promise1,
                'second' => $promise2,
            ])->then(function(array $values): void {
                $this->assertArraysAreIdentical(
                    [
                        'first' => 1,
                        'second' => 2,
                    ],
                    $values
                );
            });
        } finally {
            socket_close($reader);
            socket_close($writer);
        }
    }

    public function testAny(): void
    {
        $sockets = [];

        $result = socket_create_pair(
            AF_UNIX,
            SOCK_STREAM,
            0,
            $sockets
        );

        $this->assertTrue($result);

        [$reader, $writer] = $sockets;

        $promise1 = new AsyncPromise(static function(Closure $resolve): void {
            $resolve(1);
        });

        $promise2 = new AsyncPromise(static function(Closure $resolve) use ($reader): void {
            socket_read($reader, 1);
            $resolve(3);
        });

        try {
            Promise::any([$promise1, $promise2])
                ->then(function(int $value): void {
                    $this->assertSame(
                        1,
                        $value
                    );
                });
        } finally {
            socket_write($writer, '1');
            $promise2->wait();

            socket_close($reader);
            socket_close($writer);
        }
    }

    public function testAnyReject(): void
    {
        $promise1 = new AsyncPromise(static function(Closure $resolve, Closure $reject): void {
            $reject();
        });

        $promise2 = new AsyncPromise(static function(Closure $resolve): void {
            $resolve(3);
        });

        Promise::any([$promise1, $promise2])
            ->then(function(int $value): void {
                $this->assertSame(
                    3,
                    $value
                );
            })
            ->catch(function(): void {
                $this->fail();
            });
    }

    public function testAnyRejectAfter(): void
    {
        $sockets = [];

        $result = socket_create_pair(
            AF_UNIX,
            SOCK_STREAM,
            0,
            $sockets
        );

        $this->assertTrue($result);

        [$reader, $writer] = $sockets;

        $promise1 = new AsyncPromise(static function(Closure $resolve): void {
            $resolve(1);
        });

        $promise2 = new AsyncPromise(static function(Closure $resolve, Closure $reject) use ($reader): void {
            socket_read($reader, 1);
            $reject();
        });

        try {
            Promise::any([$promise1, $promise2])
                ->then(function(int $value): void {
                    $this->assertSame(
                        1,
                        $value
                    );
                })
                ->catch(function(): void {
                    $this->fail();
                });
        } finally {
            socket_write($writer, '1');
            $promise2->wait();

            socket_close($reader);
            socket_close($writer);
        }
    }

    public function testAnyRejectAll(): void
    {
        $promise1 = new AsyncPromise(static function(Closure $resolve, Closure $reject): void {
            $reject(new Exception('test1'));
        });

        $promise2 = new AsyncPromise(static function(Closure $resolve, Closure $reject): void {
            $reject(new Exception('test2'));
        });

        $called = false;

        Promise::any([$promise1, $promise2])
            ->then(function(): void {
                $this->fail();
            })
            ->catch(static function() use (&$called): void {
                $called = true;
            });

        $this->assertTrue($called);
    }

    public function testAsync(): void
    {
        $sockets = [];

        $result = socket_create_pair(
            AF_UNIX,
            SOCK_STREAM,
            0,
            $sockets
        );

        $this->assertTrue($result);

        [$parentSocket, $childSocket] = $sockets;

        $promise1 = new AsyncPromise(static function(Closure $resolve) use ($childSocket): void {
            socket_write($childSocket, '1');
            socket_read($childSocket, 1);
            $resolve(1);
        });

        $promise2 = new AsyncPromise(static function(Closure $resolve) use ($childSocket): void {
            socket_write($childSocket, '1');
            socket_read($childSocket, 1);
            $resolve(2);
        });

        try {
            $this->assertSame('1', socket_read($parentSocket, 1));
            $this->assertSame('1', socket_read($parentSocket, 1));

            socket_write($parentSocket, '11');

            $this->assertArraysAreIdentical(
                [1, 2],
                Promise::all([$promise1, $promise2]) |> Promise::await(...)
            );
        } finally {
            socket_close($parentSocket);
            socket_close($childSocket);
        }
    }

    public function testAwait(): void
    {
        $promise = new AsyncPromise(static function(Closure $resolve): void {
            $resolve('test');
        });

        $this->assertSame(
            'test',
            Promise::await($promise)
        );
    }

    public function testAwaitChain(): void
    {
        $promise = new AsyncPromise(static function(Closure $resolve): void {
            $resolve(20);
        });
        $chain = $promise
            ->then(static fn(int $value): int => $value + 1)
            ->then(static fn(int $value): int => $value * 2);

        try {
            $this->assertSame(
                42,
                Promise::await($chain)
            );
        } finally {
            $promise->wait();
        }
    }

    public function testAwaitChainCatch(): void
    {
        $promise = new AsyncPromise(static function(Closure $resolve, Closure $reject): void {
            $reject(new Exception('test'));
        });
        $chain = $promise->catch(static fn(): int => 42);

        try {
            $this->assertSame(
                42,
                Promise::await($chain)
            );
        } finally {
            $promise->wait();
        }
    }

    public function testAwaitChainFinally(): void
    {
        $promise = new AsyncPromise(static function(Closure $resolve): void {
            $resolve(42);
        });
        $called = false;
        $chain = $promise->finally(static function() use (&$called): void {
            $called = true;
        });

        try {
            $this->assertSame(
                42,
                Promise::await($chain)
            );
            $this->assertTrue(
                $called
            );
        } finally {
            $promise->wait();
        }
    }

    public function testAwaitChainRejection(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageIs('test');

        $promise = new AsyncPromise(static function(Closure $resolve, Closure $reject): void {
            $reject(new Exception('test'));
        });

        try {
            Promise::await($promise->then(static fn(int $value): int => $value * 2));
        } finally {
            $promise->wait();
        }
    }

    public function testAwaitChainReturnsAsyncPromise(): void
    {
        $promise = new AsyncPromise(static function(Closure $resolve): void {
            $resolve(21);
        });
        $next = null;
        $chain = $promise->then(static function(int $value) use (&$next): AsyncPromise {
            $next = new AsyncPromise(static function(Closure $resolve) use ($value): void {
                $resolve($value * 2);
            });

            return $next;
        });

        try {
            $this->assertSame(
                42,
                Promise::await($chain)
            );
        } finally {
            $promise->wait();
            $next?->wait();
        }
    }

    public function testAwaitLargeRejection(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageIs(str_repeat('test', 524288));

        $promise = new class (static function(Closure $resolve, Closure $reject): void {
            $reject(new Exception(str_repeat('test', 524288)));
        }) extends AsyncPromise
        {

            #[Override]
            protected static int $maxRunTime = 5;
        };

        Promise::await($promise);
    }

    public function testAwaitLargeResult(): void
    {
        $value = str_repeat('test', 524288);

        $promise = new class (static function(Closure $resolve) use ($value): void {
            $resolve($value);
        }) extends AsyncPromise
        {

            #[Override]
            protected static int $maxRunTime = 5;
        };

        $this->assertSame(
            $value,
            Promise::await($promise)
        );
    }

    public function testAwaitRejection(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageIs('test');

        $promise = new AsyncPromise(static function(Closure $resolve, Closure $reject): void {
            $reject(new Exception('test'));
        });

        Promise::await($promise);
    }

    public function testCancel(): void
    {
        $this->expectException(CancelledPromiseException::class);
        $this->expectExceptionMessageIs('test');

        $sockets = [];

        $result = socket_create_pair(
            AF_UNIX,
            SOCK_STREAM,
            0,
            $sockets
        );

        $this->assertTrue($result);

        [$reader, $writer] = $sockets;

        $promise = new AsyncPromise(static function(Closure $resolve) use ($reader): void {
            socket_read($reader, 1);
            $resolve(1);
        });

        try {
            $promise->cancel('test');

            $pid = Closure::bind(function(): int|null {
                /** @var AsyncPromise $this */
                return $this->pid;
            }, $promise, AsyncPromise::class)();

            $this->assertNull($pid);

            Promise::await($promise);
        } finally {
            socket_close($reader);
            socket_close($writer);
        }
    }

    public function testCancelSettled(): void
    {
        $promise = new AsyncPromise(static function(Closure $resolve): void {
            $resolve(1);
        });

        $promise->wait();

        $pid = Closure::bind(function(): int|null {
            /** @var AsyncPromise $this */
            return $this->pid;
        }, $promise, AsyncPromise::class)();

        $this->assertNull($pid);

        $promise->cancel();

        $this->assertSame(
            1,
            Promise::await($promise)
        );
    }

    public function testCancelTimeout(): void
    {
        $this->expectException(CancelledPromiseException::class);
        $this->expectExceptionMessageIs('Promise was cancelled.');

        $sockets = [];

        $result = socket_create_pair(
            AF_UNIX,
            SOCK_STREAM,
            0,
            $sockets
        );

        $this->assertTrue($result);

        [$reader, $writer] = $sockets;

        $promise = new AsyncPromise(static function(Closure $resolve) use ($reader): void {
            socket_read($reader, 1);
            $resolve(1);
        });

        Closure::bind(function(): void {
            /** @var AsyncPromise $this */
            $this->startTime = time() - 301;
        }, $promise, AsyncPromise::class)();

        try {
            Promise::await($promise);
        } finally {
            socket_close($reader);
            socket_close($writer);
        }
    }

    public function testCatch(): void
    {
        $promise = new AsyncPromise(static function(Closure $resolve, Closure $reject): void {
            $reject();
        });

        $called = false;

        $promise->catch(static function() use (&$called): void {
            $called = true;
        });

        $promise->wait();

        $this->assertTrue($called);
    }

    public function testCatchReason(): void
    {
        $promise = new AsyncPromise(static function(Closure $resolve, Closure $reject): never {
            throw new Exception('test');
        });

        $promise->catch(function(Throwable $reason): void {
            $this->assertSame(
                'test',
                $reason->getMessage()
            );
        });

        $promise->wait();
    }

    public function testCatchThen(): void
    {
        $promise = new AsyncPromise(static function(Closure $resolve, Closure $reject): never {
            throw new Exception();
        });

        $promise->catch(static function(): int {
            return 1;
        })->then(function(int $value) {
            $this->assertSame(
                1,
                $value
            );
        });

        $promise->wait();
    }

    public function testCatchThenCatch(): void
    {
        $promise = new AsyncPromise(static function(Closure $resolve, Closure $reject): never {
            throw new Exception();
        });

        $promise->catch(static function(): void {})->then(static function(): never {
            throw new Exception('test');
        })->catch(function(Throwable $reason): void {
            $this->assertSame(
                'test',
                $reason->getMessage()
            );
        });

        $promise->wait();
    }

    public function testCatchThenPromise(): void
    {
        $promise = new AsyncPromise(static function(Closure $resolve, Closure $reject): never {
            throw new Exception();
        });

        $promise->catch(static function(): PromiseInterface {
            return Promise::resolve(1);
        })->then(function(int $value) {
            $this->assertSame(
                1,
                $value
            );
        });

        $promise->wait();
    }

    /**
     * @param array<string, mixed> $expected The expected result.
     */
    #[DataProvider('childCallbackProvider')]
    public function testChildCallback(Closure $callback, array $expected): void
    {
        $sockets = [];

        $result = socket_create_pair(
            AF_UNIX,
            SOCK_STREAM,
            0,
            $sockets
        );

        $this->assertTrue($result);

        [$reader, $writer] = $sockets;

        Closure::bind(static function() use ($callback, $writer): void {
            AsyncPromise::runChild($callback, $writer);
        }, null, AsyncPromise::class)();

        $data = '';
        do {
            $chunk = socket_read($reader, 4096);

            $this->assertIsString($chunk);

            $data .= $chunk;
        } while ($chunk !== '');

        socket_close($reader);

        [$reason, $value] = unserialize($data);

        $this->assertArraysAreIdentical(
            $expected,
            [
                'reason' => $reason instanceof Throwable ?
                    [$reason::class, $reason->getMessage()] :
                    null,
                'value' => $value,
            ]
        );
    }

    /**
     * @param array<int>|int $expected The expected result.
     */
    #[DataProvider('combinationProvider')]
    public function testCombinationChain(string $method, array|int $expected): void
    {
        $promise = new AsyncPromise(static function(Closure $resolve): void {
            $resolve(21);
        });
        $chain = $promise->then(static fn(int $value): int => $value * 2);

        try {
            $this->assertSame(
                $expected,
                Promise::$method([$chain]) |> Promise::await(...)
            );
        } finally {
            $promise->wait();
        }
    }

    /**
     * @param array<int>|int $expected The expected result.
     */
    #[DataProvider('combinationProvider')]
    public function testCombinationChainReturnsAsyncPromise(string $method, array|int $expected): void
    {
        $promise = new AsyncPromise(static function(Closure $resolve): void {
            $resolve(21);
        });
        $next = null;
        $chain = $promise->then(static function(int $value) use (&$next): AsyncPromise {
            $next = new AsyncPromise(static function(Closure $resolve) use ($value): void {
                $resolve($value * 2);
            });

            return $next;
        });

        try {
            $this->assertSame(
                $expected,
                Promise::$method([$chain]) |> Promise::await(...)
            );
        } finally {
            $promise->wait();
            $next?->wait();
        }
    }

    #[DataProvider('destructProvider')]
    public function testDestruct(bool $completed, bool $race): void
    {
        $promise = new AsyncPromise(static function(Closure $resolve) use ($completed): void {
            if (!$completed) {
                sleep(30);
            }

            $resolve(1);
        });

        $pid = Closure::bind(function(): int|null {
            /** @var AsyncPromise $this */
            return $this->pid;
        }, $promise, AsyncPromise::class)();

        $this->assertNotNull($pid);

        try {
            if ($completed) {
                $this->assertTrue(
                    pcntl_waitid(P_PID, $pid, $info, WEXITED | WNOWAIT)
                );
            }

            if ($race) {
                $this->assertSame(
                    'winner',
                    Promise::race(['winner', $promise]) |> Promise::await(...)
                );
            }

            $called = false;
            $promise->finally(static function() use (&$called): void {
                $called = true;
            });

            unset($promise);
            gc_collect_cycles();

            $this->assertFalse($called);

            $this->assertSame(
                -1,
                pcntl_waitpid($pid, $status, WNOHANG)
            );
        } finally {
            if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
        }
    }

    public function testDestructForkedCopy(): void
    {
        $sockets = [];

        $result = socket_create_pair(
            AF_UNIX,
            SOCK_STREAM,
            0,
            $sockets
        );

        $this->assertTrue($result);

        [$reader, $writer] = $sockets;

        $promise = new AsyncPromise(static function(Closure $resolve) use ($reader): void {
            socket_read($reader, 1);
            $resolve(1);
        });

        $pid = pcntl_fork();

        if ($pid === 0) {
            unset($promise);
            gc_collect_cycles();
            exit(0);
        }

        try {
            $this->assertGreaterThan(0, $pid);

            $this->assertSame(
                $pid,
                pcntl_waitpid($pid, $status)
            );

            $this->assertSame(
                0,
                pcntl_wexitstatus($status)
            );

            socket_write($writer, '1');

            $this->assertSame(
                1,
                Promise::await($promise)
            );
        } finally {
            socket_close($reader);
            socket_close($writer);
        }
    }

    public function testMultipleThen(): void
    {
        $promise = new AsyncPromise(static function(Closure $resolve): void {
            $resolve(1);
        });

        $results = [];

        $promise->then(static function(int $value) use (&$results): void {
            $results[] = $value;
        });

        $promise->then(static function(int $value) use (&$results): void {
            $results[] = $value + 1;
        });

        $promise->wait();

        $this->assertArraysAreIdentical(
            [1, 2],
            $results
        );
    }

    public function testPollInvalidResult(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Unable to read response from child process.');

        $promise = new AsyncPromise(static function(Closure $resolve): void {});

        try {
            $promise->wait();
        } finally {
            $pid = Closure::bind(function(): int|null {
                /** @var AsyncPromise $this */
                return $this->pid;
            }, $promise, AsyncPromise::class)();

            $this->assertNull($pid);
        }
    }

    public function testRace(): void
    {
        $sockets = [];

        $result = socket_create_pair(
            AF_UNIX,
            SOCK_STREAM,
            0,
            $sockets
        );

        $this->assertTrue($result);

        [$reader, $writer] = $sockets;

        $promise1 = new AsyncPromise(static function(Closure $resolve): void {
            $resolve(1);
        });

        $promise2 = new AsyncPromise(static function(Closure $resolve) use ($reader): void {
            socket_read($reader, 1);
            $resolve(3);
        });

        try {
            Promise::race([$promise1, $promise2])
                ->then(function(int $value): void {
                    $this->assertSame(
                        1,
                        $value
                    );
                });
        } finally {
            socket_write($writer, '1');
            $promise2->wait();

            socket_close($reader);
            socket_close($writer);
        }
    }

    public function testRaceReject(): void
    {
        $sockets = [];

        $result = socket_create_pair(
            AF_UNIX,
            SOCK_STREAM,
            0,
            $sockets
        );

        $this->assertTrue($result);

        [$reader, $writer] = $sockets;

        $promise1 = new AsyncPromise(static function(Closure $resolve, Closure $reject): void {
            $reject(new Exception('test'));
        });

        $promise2 = new AsyncPromise(static function(Closure $resolve) use ($reader): void {
            socket_read($reader, 1);
            $resolve(3);
        });

        try {
            Promise::race([$promise1, $promise2])
                ->catch(function(Throwable $reason): void {
                    $this->assertSame(
                        'test',
                        $reason->getMessage()
                    );
                });
        } finally {
            socket_write($writer, '1');
            $promise2->wait();

            socket_close($reader);
            socket_close($writer);
        }
    }

    public function testRaceRejectAfter(): void
    {
        $sockets = [];

        $result = socket_create_pair(
            AF_UNIX,
            SOCK_STREAM,
            0,
            $sockets
        );

        $this->assertTrue($result);

        [$reader, $writer] = $sockets;

        $promise1 = new AsyncPromise(static function(Closure $resolve): void {
            $resolve(1);
        });

        $promise2 = new AsyncPromise(static function(Closure $resolve, Closure $reject) use ($reader): void {
            socket_read($reader, 1);
            $reject();
        });

        try {
            Promise::race([$promise1, $promise2])
                ->then(function(int $value): void {
                    $this->assertSame(
                        1,
                        $value
                    );
                })
                ->catch(function(): void {
                    $this->fail();
                });
        } finally {
            socket_write($writer, '1');
            $promise2->wait();

            socket_close($reader);
            socket_close($writer);
        }
    }

    public function testThen(): void
    {
        $promise = new AsyncPromise(static function(Closure $resolve): void {
            $resolve();
        });

        $called = false;

        $promise->then(static function() use (&$called): void {
            $called = true;
        });

        $promise->wait();

        $this->assertTrue($called);
    }

    public function testThenResolve(): void
    {
        $promise = new AsyncPromise(static function(Closure $resolve): void {
            $resolve(1);
        });

        $promise->then(function(int $value): void {
            $this->assertSame(
                1,
                $value
            );
        });

        $promise->wait();
    }

    public function testUncaughtException(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageIs('test');

        $promise = new AsyncPromise(static function(Closure $resolve, Closure $reject): void {
            $reject(new Exception('test'));
        });

        $promise->wait();
    }

    public function testWaitFinally(): void
    {
        $promise = new AsyncPromise(static function(Closure $resolve): void {
            $resolve();
        });

        $promise->wait();

        $called = false;

        $promise->finally(static function() use (&$called): void {
            $called = true;
        });

        $this->assertTrue($called);
    }

    public function testWaitThen(): void
    {
        $promise = new AsyncPromise(static function(Closure $resolve): void {
            $resolve();
        });

        $promise->wait();

        $called = false;

        $promise->then(static function() use (&$called): void {
            $called = true;
        });

        $this->assertTrue($called);
    }
}
