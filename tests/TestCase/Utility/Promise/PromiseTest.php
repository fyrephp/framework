<?php
declare(strict_types=1);

namespace Tests\TestCase\Utility\Promise;

use Closure;
use Exception;
use Fyre\Core\Traits\DebugTrait;
use Fyre\Core\Traits\MacroTrait;
use Fyre\Core\Traits\StaticMacroTrait;
use Fyre\Utility\Promise\Promise;
use Fyre\Utility\Promise\PromiseInterface;
use LogicException;
use PHPUnit\Framework\TestCase;
use Throwable;

use function array_diff;
use function class_uses;

final class PromiseTest extends TestCase
{
    public function testCatch(): void
    {
        $called = false;

        new Promise(static function(Closure $resolve, Closure $reject): void {
            $reject();
        })->catch(static function() use (&$called): void {
            $called = true;
        });

        $this->assertTrue($called);
    }

    public function testCatchCatch(): void
    {
        $this->expectNotToPerformAssertions();

        Promise::reject()
            ->catch(static function(): void {})
            ->catch(function(): void {
                $this->fail();
            });
    }

    public function testCatchCatchException(): void
    {
        Promise::reject()
            ->catch(static function(): void {
                throw new Exception('test');
            })
            ->catch(function(Throwable $reason): void {
                $this->assertSame(
                    'test',
                    $reason->getMessage()
                );
            });
    }

    public function testCatchException(): void
    {
        new Promise(static function(Closure $resolve, Closure $reject): void {
            throw new Exception('test');
        })->catch(function(Throwable $reason): void {
            $this->assertSame(
                'test',
                $reason->getMessage()
            );
        });
    }

    public function testCatchFinally(): void
    {
        $called = false;

        Promise::reject()
            ->catch(static function(): void {})
            ->finally(static function() use (&$called): void {
                $called = true;
            });

        $this->assertTrue($called);
    }

    public function testCatchReason(): void
    {
        new Promise(static function(Closure $resolve, Closure $reject): void {
            $reject(new Exception('test'));
        })->catch(function(Throwable $reason): void {
            $this->assertSame(
                'test',
                $reason->getMessage()
            );
        });
    }

    public function testCatchThen(): void
    {
        new Promise(static function(Closure $resolve, Closure $reject): void {
            throw new Exception();
        })->catch(static function(): int {
            return 1;
        })->then(function(int $value) {
            $this->assertSame(
                1,
                $value
            );
        });
    }

    public function testCatchThenCatch(): void
    {
        new Promise(static function(Closure $resolve, Closure $reject): void {
            throw new Exception();
        })->catch(static function(): void {})->then(static function(): void {
            throw new Exception('test');
        })->catch(function(Throwable $reason): void {
            $this->assertSame(
                'test',
                $reason->getMessage()
            );
        });
    }

    public function testCatchThenPromise(): void
    {
        new Promise(static function(Closure $resolve, Closure $reject): void {
            throw new Exception();
        })->catch(static function(): PromiseInterface {
            return Promise::resolve(1);
        })->then(function(int $value) {
            $this->assertSame(
                1,
                $value
            );
        });
    }

    public function testDebug(): void
    {
        $this->assertContains(
            DebugTrait::class,
            class_uses(Promise::class)
        );
    }

    public function testMacro(): void
    {
        $this->assertEmpty(
            array_diff([MacroTrait::class, StaticMacroTrait::class], class_uses(Promise::class))
        );
    }

    public function testMultipleThen(): void
    {
        $promise = new Promise(static function(Closure $resolve): void {
            $resolve(1);
        });

        $results = [];

        $promise->then(static function(int $value) use (&$results): void {
            $results[] = $value;
        });

        $promise->then(static function(int $value) use (&$results): void {
            $results[] = $value + 1;
        });

        $this->assertArraysAreIdentical(
            [1, 2],
            $results
        );
    }

    public function testRejectedFinallyPreservesReason(): void
    {
        $exception = new Exception('test');
        $reason = null;

        Promise::reject($exception)
            ->finally(static fn(): string => 'ignored')
            ->catch(static function(Throwable $error) use (&$reason): void {
                $reason = $error;
            });

        $this->assertSame($exception, $reason);
    }

    public function testRejectedFinallyReplacesReasonOnException(): void
    {
        $exception = new Exception('cleanup');
        $reason = null;

        Promise::reject(new Exception('test'))
            ->finally(static fn(): never => throw $exception)
            ->catch(static function(Throwable $error) use (&$reason): void {
                $reason = $error;
            });

        $this->assertSame($exception, $reason);
    }

    public function testRejectedFinallyWaitsForPromise(): void
    {
        $resolve = null;
        $cleanup = new Promise(static function(Closure $resolver) use (&$resolve): void {
            $resolve = $resolver;
        });

        $exception = new Exception('test');
        $reason = null;

        Promise::reject($exception)
            ->finally(static fn(): PromiseInterface => $cleanup)
            ->catch(static function(Throwable $error) use (&$reason): void {
                $reason = $error;
            });

        $this->assertNull($reason);

        $this->assertInstanceOf(Closure::class, $resolve);
        $resolve();

        $this->assertSame($exception, $reason);
    }

    public function testThen(): void
    {
        $called = false;

        new Promise(static function(Closure $resolve): void {
            $resolve();
        })->then(static function() use (&$called): void {
            $called = true;
        });

        $this->assertTrue($called);
    }

    public function testThenAssimilatesSettledPromise(): void
    {
        $resolve = null;
        $promise = new Promise(static function(Closure $resolver) use (&$resolve): void {
            $resolve = $resolver;
        });

        $value = null;
        $promise
            ->then(static fn(): PromiseInterface => new Promise(static function(Closure $resolve): void {
                $resolve(1);
            }))
            ->then(static function(int $result) use (&$value): void {
                $value = $result;
            });

        $this->assertInstanceOf(Closure::class, $resolve);
        $resolve();

        $this->assertSame(1, $value);
    }

    public function testThenCatch(): void
    {
        Promise::reject(new Exception('test'))
            ->then(function(): void {
                $this->fail();
            })
            ->catch(function(Throwable $reason): void {
                $this->assertSame(
                    'test',
                    $reason->getMessage()
                );
            });
    }

    public function testThenCatchFinallyThenCatchFinally(): void
    {
        $results = [];

        Promise::resolve(1)
            ->then(static function(int $value) use (&$results): int {
                $results[] = $value;

                return 2;
            })
            ->catch(function(): void {
                $this->fail();
            })
            ->finally(static function() use (&$results): void {
                $results[] = 3;
            })
            ->then(static function(int $value) use (&$results): int {
                $results[] = $value;

                return 4;
            })
            ->catch(function(): void {
                $this->fail();
            })
            ->finally(static function() use (&$results): void {
                $results[] = 5;
            });

        $this->assertArraysAreIdentical(
            [1, 3, 2, 5],
            $results
        );
    }

    public function testThenFinally(): void
    {
        $called = false;

        Promise::resolve()
            ->then(static function(): void {})
            ->finally(static function() use (&$called): void {
                $called = true;
            });

        $this->assertTrue($called);
    }

    public function testThenRejectsSelfResolution(): void
    {
        $resolve = null;
        $promise = new Promise(static function(Closure $resolver) use (&$resolve): void {
            $resolve = $resolver;
        });

        $next = null;
        $next = $promise->then(static function() use (&$next): PromiseInterface {
            if (!($next instanceof PromiseInterface)) {
                throw new LogicException('Chained promise was not initialized.');
            }

            return $next;
        });

        $reason = null;
        $next->catch(static function(Throwable $error) use (&$reason): void {
            $reason = $error;
        });

        $this->assertInstanceOf(Closure::class, $resolve);
        $resolve();

        $this->assertInstanceOf(LogicException::class, $reason);

        $this->assertSame(
            'Cannot resolve a promise with itself.',
            $reason->getMessage()
        );
    }

    public function testThenResolve(): void
    {
        new Promise(static function(Closure $resolve): void {
            $resolve(1);
        })->then(function(int $value): void {
            $this->assertSame(
                1,
                $value
            );
        });
    }

    public function testThenThen(): void
    {
        Promise::resolve(1)
            ->then(static fn(int $value): int => $value + 1)
            ->then(function(int $value): void {
                $this->assertSame(
                    2,
                    $value
                );
            });
    }

    public function testThenThenCatch(): void
    {
        Promise::resolve()
            ->then(static function(): void {
                throw new Exception('test');
            })
            ->then(function(): void {
                $this->fail();
            })
            ->catch(function(Throwable $reason): void {
                $this->assertSame(
                    'test',
                    $reason->getMessage()
                );
            });
    }

    public function testThenThenPromise(): void
    {
        Promise::resolve()
            ->then(static fn(): PromiseInterface => Promise::resolve(1))
            ->then(function(int $value): void {
                $this->assertSame(
                    1,
                    $value
                );
            });
    }

    public function testThenThenThen(): void
    {
        Promise::resolve()
            ->then(static fn(): int => 1)
            ->then(static fn(int $value): int => $value + 1)
            ->then(function(int $value): void {
                $this->assertSame(
                    2,
                    $value
                );
            });
    }

    public function testUncaughtCaughtException(): void
    {
        $this->expectException(Exception::class);

        Promise::reject()->catch(static function(): void {
            throw new Exception();
        });
    }

    public function testUncaughtException(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageIs('test');

        new Promise(static function(Closure $resolve, Closure $reject): void {
            $reject(new Exception('test'));
        });
    }

    public function testUncaughtThenException(): void
    {
        $this->expectException(Exception::class);

        Promise::resolve(1)->then(static function(): void {
            throw new Exception();
        });
    }
}
