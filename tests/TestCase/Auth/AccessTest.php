<?php
declare(strict_types=1);

namespace Tests\TestCase\Auth;

use Exception;
use Fyre\Auth\Access;
use Fyre\Core\Traits\DebugTrait;
use Fyre\Core\Traits\MacroTrait;
use Fyre\Http\Exceptions\ForbiddenException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Mock\Entities\User;

use function class_uses;

final class AccessTest extends TestCase
{
    use ConnectionTrait;

    /**
     * @return array<string, array{bool, bool, bool, int}>
     */
    public static function anyProvider(): array
    {
        return [
            'first rule allows' => [true, true, true, 1],
            'both rules deny' => [false, false, false, 2],
            'second rule allows' => [false, true, true, 2],
        ];
    }

    public function testAfter(): void
    {
        $this->login();

        $ranAfter = false;
        $this->access->after(function(User|null $authUser, string $rule, bool|null $result) use (&$ranAfter): bool {
            $ranAfter = true;

            $this->assertInstanceOf(User::class, $authUser);
            $this->assertSame($rule, 'test');
            $this->assertNull($result);

            return true;
        });

        $this->assertTrue(
            $this->access->allows('test')
        );

        $this->assertTrue($ranAfter);
    }

    public function testAfterFail(): void
    {
        $this->login();

        $ranAfter = false;
        $this->access->after(function(User|null $authUser, string $rule, bool|null $result) use (&$ranAfter): bool {
            $ranAfter = true;

            $this->assertInstanceOf(User::class, $authUser);
            $this->assertSame($rule, 'test');
            $this->assertNull($result);

            return false;
        });

        $this->assertFalse(
            $this->access->allows('test')
        );

        $this->assertTrue($ranAfter);
    }

    public function testAfterMultiple(): void
    {
        $this->login();

        $ranAfter = 0;
        $this->access->after(function(User|null $authUser, string $rule, bool|null $result) use (&$ranAfter): void {
            $ranAfter++;

            $this->assertInstanceOf(User::class, $authUser);
            $this->assertSame($rule, 'test');
            $this->assertNull($result);
        });
        $this->access->after(function(User|null $authUser, string $rule, bool|null $result) use (&$ranAfter): bool {
            $ranAfter++;

            $this->assertInstanceOf(User::class, $authUser);
            $this->assertSame($rule, 'test');
            $this->assertNull($result);

            return true;
        });

        $this->assertTrue(
            $this->access->allows('test')
        );

        $this->assertSame(
            2,
            $ranAfter
        );
    }

    public function testAfterMultipleResult(): void
    {
        $this->login();

        $ranAfter = 0;
        $this->access->after(function(User|null $authUser, string $rule, bool|null $result) use (&$ranAfter): bool {
            $ranAfter++;

            $this->assertInstanceOf(User::class, $authUser);
            $this->assertSame($rule, 'test');
            $this->assertNull($result);

            return true;
        });
        $this->access->after(function(User|null $authUser, string $rule, bool|null $result) use (&$ranAfter): bool {
            $ranAfter++;

            $this->assertInstanceOf(User::class, $authUser);
            $this->assertSame($rule, 'test');
            $this->assertTrue($result);

            return false;
        });

        $this->assertTrue(
            $this->access->allows('test')
        );

        $this->assertSame(
            2,
            $ranAfter
        );
    }

    public function testAfterRequiresUser(): void
    {
        $this->access->after(static fn(User $authUser): bool => false);
        $this->access->after(static fn(User|null $authUser): bool => true);

        $this->assertTrue(
            $this->access->allows('test')
        );
    }

    public function testAfterResult(): void
    {
        $this->login();

        $ranAfter = false;
        $this->access->after(function(User|null $authUser, string $rule, bool|null $result) use (&$ranAfter): bool {
            $ranAfter = true;

            $this->assertInstanceOf(User::class, $authUser);
            $this->assertSame($rule, 'test');
            $this->assertTrue($result);

            return false;
        });

        $ran = false;
        $this->access->define('test', function(User|null $authUser) use (&$ran): bool {
            $ran = true;

            $this->assertInstanceOf(User::class, $authUser);

            return true;
        });

        $this->assertTrue(
            $this->access->allows('test')
        );

        $this->assertTrue($ranAfter);
        $this->assertTrue($ran);
    }

    public function testAllows(): void
    {
        $this->login();

        $ran = false;
        $this->access->define('test', function(User|null $authUser) use (&$ran): bool {
            $ran = true;

            $this->assertInstanceOf(User::class, $authUser);

            return true;
        });

        $this->assertTrue(
            $this->access->allows('test')
        );

        $this->assertTrue($ran);
    }

    public function testAllowsArguments(): void
    {
        $this->login();

        $ran = false;
        $this->access->define('test', function(User|null $authUser, string $value) use (&$ran): bool {
            $ran = true;

            $this->assertInstanceOf(User::class, $authUser);
            $this->assertSame('test', $value);

            return true;
        });

        $this->assertTrue(
            $this->access->allows('test', 'test')
        );

        $this->assertTrue($ran);
    }

    public function testAllowsFail(): void
    {
        $this->login();

        $ran = false;
        $this->access->define('test', function(User|null $authUser) use (&$ran): bool {
            $ran = true;

            $this->assertInstanceOf(User::class, $authUser);

            return false;
        });

        $this->assertFalse(
            $this->access->allows('test')
        );

        $this->assertTrue($ran);
    }

    public function testAllowsRequiresUser(): void
    {
        $this->access->define('test', static fn(User $authUser): bool => true);

        $this->assertFalse(
            $this->access->allows('test')
        );
    }

    #[DataProvider('anyProvider')]
    public function testAny(bool $firstResult, bool $secondResult, bool $expected, int $expectedCalls): void
    {
        $this->login();

        $ran = 0;
        $this->access->define('test', function(User|null $authUser) use (&$ran, $firstResult): bool {
            $ran++;

            $this->assertInstanceOf(User::class, $authUser);

            return $firstResult;
        });
        $this->access->define('test2', function(User|null $authUser) use (&$ran, $secondResult): bool {
            $ran++;

            $this->assertInstanceOf(User::class, $authUser);

            return $secondResult;
        });

        $this->assertSame(
            $expected,
            $this->access->any(['test', 'test2'])
        );

        $this->assertSame($expectedCalls, $ran);
    }

    public function testAnyArguments(): void
    {
        $this->login();

        $ran = 0;
        $this->access->define('test', function(User|null $authUser, string $value) use (&$ran): bool {
            $ran++;

            $this->assertInstanceOf(User::class, $authUser);
            $this->assertSame('test', $value);

            return true;
        });
        $this->access->define('test2', function(User|null $authUser, string $value) use (&$ran): bool {
            $ran++;

            $this->assertInstanceOf(User::class, $authUser);
            $this->assertSame('test', $value);

            return true;
        });

        $this->assertTrue(
            $this->access->any(['test', 'test2'], 'test')
        );

        $this->assertSame(1, $ran);
    }

    public function testAuthorize(): void
    {
        $this->login();

        $ran = false;
        $this->access->define('test', function(User|null $authUser) use (&$ran): bool {
            $ran = true;

            $this->assertInstanceOf(User::class, $authUser);

            return true;
        });

        $this->access->authorize('test');

        $this->assertTrue($ran);
    }

    public function testAuthorizeFail(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->expectExceptionCode(403);
        $this->expectExceptionMessageIs('Forbidden');

        $ran = false;
        $this->access->define('test', function(User|null $authUser) use (&$ran): bool {
            $ran = true;

            $this->assertNull($authUser);

            return false;
        });

        $this->access->authorize('test');

        $this->assertTrue($ran);
    }

    public function testBefore(): void
    {
        $this->login();

        $ranBefore = false;
        $this->access->before(function(User|null $authUser, string $rule) use (&$ranBefore): bool {
            $ranBefore = true;

            $this->assertInstanceOf(User::class, $authUser);
            $this->assertSame($rule, 'test');

            return true;
        });

        $this->assertTrue(
            $this->access->allows('test')
        );

        $this->assertTrue($ranBefore);
    }

    public function testBeforeFail(): void
    {
        $this->login();

        $ranBefore = false;
        $this->access->before(function(User|null $authUser, string $rule) use (&$ranBefore): bool {
            $ranBefore = true;

            $this->assertInstanceOf(User::class, $authUser);
            $this->assertSame($rule, 'test');

            return false;
        });

        $this->assertFalse(
            $this->access->allows('test')
        );

        $this->assertTrue($ranBefore);
    }

    public function testBeforeMultiple(): void
    {
        $this->login();

        $ranBefore = 0;
        $this->access->before(function(User|null $authUser, string $rule) use (&$ranBefore): void {
            $ranBefore++;

            $this->assertInstanceOf(User::class, $authUser);
            $this->assertSame($rule, 'test');
        });
        $this->access->before(function(User|null $authUser, string $rule) use (&$ranBefore): bool {
            $ranBefore++;

            $this->assertInstanceOf(User::class, $authUser);
            $this->assertSame($rule, 'test');

            return true;
        });

        $this->assertTrue(
            $this->access->allows('test')
        );

        $this->assertSame(
            2,
            $ranBefore
        );
    }

    public function testBeforeMultipleFail(): void
    {
        $this->login();

        $ranBefore = false;
        $this->access->before(function(User|null $authUser, string $rule) use (&$ranBefore): bool {
            $ranBefore = true;

            $this->assertInstanceOf(User::class, $authUser);
            $this->assertSame($rule, 'test');

            return false;
        });
        $this->access->before(static function(User|null $authUser, string $rule): void {
            throw new Exception();
        });

        $this->assertFalse(
            $this->access->allows('test')
        );

        $this->assertTrue(
            $ranBefore
        );
    }

    public function testBeforeMultipleResult(): void
    {
        $this->login();

        $ranBefore = false;
        $this->access->before(function(User|null $authUser, string $rule) use (&$ranBefore): bool {
            $ranBefore = true;

            $this->assertInstanceOf(User::class, $authUser);
            $this->assertSame($rule, 'test');

            return true;
        });
        $this->access->before(static function(User|null $authUser, string $rule): void {
            throw new Exception();
        });

        $this->assertTrue(
            $this->access->allows('test')
        );

        $this->assertTrue(
            $ranBefore
        );
    }

    public function testBeforeRequiresUser(): void
    {
        $this->access->before(static fn(User $authUser): bool => false);
        $this->access->before(static fn(User|null $authUser): bool => true);

        $this->assertTrue(
            $this->access->allows('test')
        );
    }

    public function testDebug(): void
    {
        $this->assertContains(
            DebugTrait::class,
            class_uses(Access::class)
        );
    }

    public function testDenies(): void
    {
        $ran = false;
        $this->access->define('test', function(User|null $authUser) use (&$ran): bool {
            $ran = true;

            $this->assertNull($authUser);

            return false;
        });

        $this->assertTrue(
            $this->access->denies('test')
        );

        $this->assertTrue($ran);
    }

    public function testDeniesArguments(): void
    {
        $ran = false;
        $this->access->define('test', function(User|null $authUser, string $value) use (&$ran): bool {
            $ran = true;

            $this->assertNull($authUser);
            $this->assertSame('test', $value);

            return false;
        });

        $this->assertTrue(
            $this->access->denies('test', 'test')
        );

        $this->assertTrue($ran);
    }

    public function testDeniesFail(): void
    {
        $this->login();

        $ran = false;
        $this->access->define('test', function(User|null $authUser) use (&$ran): bool {
            $ran = true;

            $this->assertInstanceOf(User::class, $authUser);

            return true;
        });

        $this->assertFalse(
            $this->access->denies('test')
        );

        $this->assertTrue($ran);
    }

    public function testMacro(): void
    {
        $this->assertContains(
            MacroTrait::class,
            class_uses(Access::class)
        );
    }

    public function testNone(): void
    {
        $this->login();

        $ran = 0;
        $this->access->define('test', function(User|null $authUser) use (&$ran): bool {
            $ran++;

            $this->assertInstanceOf(User::class, $authUser);

            return false;
        });
        $this->access->define('test2', function(User|null $authUser) use (&$ran): bool {
            $ran++;

            $this->assertInstanceOf(User::class, $authUser);

            return false;
        });

        $this->assertTrue(
            $this->access->none(['test', 'test2'])
        );

        $this->assertSame(2, $ran);
    }

    public function testNoneArguments(): void
    {
        $this->login();

        $ran = 0;
        $this->access->define('test', function(User|null $authUser, string $value) use (&$ran): bool {
            $ran++;

            $this->assertInstanceOf(User::class, $authUser);
            $this->assertSame('test', $value);

            return false;
        });
        $this->access->define('test2', function(User|null $authUser, string $value) use (&$ran): bool {
            $ran++;

            $this->assertInstanceOf(User::class, $authUser);
            $this->assertSame('test', $value);

            return false;
        });

        $this->assertTrue(
            $this->access->none(['test', 'test2'], 'test')
        );

        $this->assertSame(2, $ran);
    }

    public function testNoneFail(): void
    {
        $this->login();

        $ran = 0;
        $this->access->define('test', function(User|null $authUser) use (&$ran): bool {
            $ran++;

            $this->assertInstanceOf(User::class, $authUser);

            return false;
        });
        $this->access->define('test2', function(User|null $authUser) use (&$ran): bool {
            $ran++;

            $this->assertInstanceOf(User::class, $authUser);

            return true;
        });

        $this->assertFalse(
            $this->access->none(['test', 'test2'])
        );

        $this->assertSame(2, $ran);
    }

    public function testOrder(): void
    {
        $results = [];

        $this->access->define('test', static function() use (&$results): bool {
            $results[] = 1;

            return true;
        });

        $this->access->before(static function() use (&$results): void {
            $results[] = 2;
        });

        $this->access->before(static function() use (&$results): void {
            $results[] = 3;
        });

        $this->access->after(static function() use (&$results): void {
            $results[] = 4;
        });

        $this->access->after(static function() use (&$results): void {
            $results[] = 5;
        });

        $this->access->allows('test');

        $this->assertArraysAreIdentical(
            [2, 3, 1, 4, 5],
            $results
        );
    }
}
