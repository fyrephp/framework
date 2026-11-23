<?php
declare(strict_types=1);

namespace Tests\TestCase\Auth;

use Fyre\Auth\Identifier;
use Fyre\Core\Traits\DebugTrait;
use Fyre\Core\Traits\MacroTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Mock\Entities\User;
use Tests\Mock\Models\UsersModel;

use function class_uses;
use function password_hash;
use function password_needs_rehash;

final class IdentifierTest extends TestCase
{
    use ConnectionTrait;

    /**
     * @return array<string, array{string, bool}>
     */
    public static function concurrentPasswordChangeProvider(): array
    {
        return [
            'password reset' => ['new password', false],
            'another login rehashed the same password' => ['test', true],
        ];
    }

    public function testAttempt(): void
    {
        $authUser = $this->identifier->attempt('test@test.com', 'test');

        $this->assertInstanceOf(
            User::class,
            $authUser
        );
    }

    public function testAttemptInvalidPassword(): void
    {
        $authUser = $this->identifier->attempt('test@test.com', 'invalid');

        $this->assertNull($authUser);
    }

    public function testAttemptInvalidUsername(): void
    {
        $authUser = $this->identifier->attempt('invalid@test.com', 'any');

        $this->assertNull($authUser);
    }

    public function testAttemptRehash(): void
    {
        $authUser = $this->identifier->identify('test@test.com');

        $this->assertInstanceOf(
            User::class,
            $authUser
        );

        $authUser->password = password_hash('test', PASSWORD_ARGON2I);

        $this->identifier->getModel()->save($authUser);

        $authUser = $this->identifier->attempt('test@test.com', 'test');

        $this->assertInstanceOf(
            User::class,
            $authUser
        );

        $authUser = $this->identifier->identify('test@test.com');

        $this->assertInstanceOf(
            User::class,
            $authUser
        );

        $password = $authUser->password;

        $this->assertIsString($password);

        $this->assertFalse(
            password_needs_rehash($password, PASSWORD_DEFAULT)
        );
    }

    #[DataProvider('concurrentPasswordChangeProvider')]
    public function testAttemptRehashWithConcurrentPasswordChange(string $password, bool $expectedLogin): void
    {
        $Users = $this->identifier->getModel();
        $user = $this->identifier->identify('test@test.com');
        $this->assertInstanceOf(User::class, $user);

        $user->password = password_hash('test', PASSWORD_ARGON2I);
        $Users->save($user);

        $currentHash = password_hash($password, PASSWORD_DEFAULT);
        $Users->getEventManager()->on('ORM.afterFind', static function() use ($Users, $user, $currentHash): void {
            $Users->getEventManager()->off('ORM.afterFind');
            $user->password = $currentHash;
            $Users->save($user);
        });

        $authUser = $this->identifier->attempt('test@test.com', 'test');

        if ($expectedLogin) {
            $this->assertInstanceOf(User::class, $authUser);
            $this->assertSame($currentHash, $authUser->password);
        } else {
            $this->assertNull($authUser);
        }

        $this->assertSame(
            $currentHash,
            $this->identifier->identify('test@test.com')?->get('password')
        );
    }

    public function testDebug(): void
    {
        $this->assertContains(
            DebugTrait::class,
            class_uses(Identifier::class)
        );
    }

    public function testGetIdentifierFields(): void
    {
        $this->assertArraysAreIdentical(
            ['username', 'email'],
            $this->identifier->getIdentifierFields()
        );
    }

    public function testGetModel(): void
    {
        $Model = $this->identifier->getModel();

        $this->assertInstanceOf(
            UsersModel::class,
            $Model
        );
    }

    public function testGetPasswordField(): void
    {
        $this->assertSame(
            'password',
            $this->identifier->getPasswordField()
        );
    }

    public function testIdentify(): void
    {
        $authUser = $this->identifier->identify('test@test.com');

        $this->assertInstanceOf(
            User::class,
            $authUser
        );
    }

    public function testIdentifyInvalid(): void
    {
        $authUser = $this->identifier->identify('invalid');

        $this->assertNull($authUser);
    }

    public function testMacro(): void
    {
        $this->assertContains(
            MacroTrait::class,
            class_uses(Identifier::class)
        );
    }
}
