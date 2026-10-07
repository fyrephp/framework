<?php
declare(strict_types=1);

namespace Tests\TestCase\Auth;

use Fyre\Auth\Auth;
use Fyre\Auth\Authenticators\SessionAuthenticator;
use Fyre\Auth\Authenticators\TokenAuthenticator;
use Fyre\Core\Traits\DebugTrait;
use Fyre\Core\Traits\MacroTrait;
use Fyre\Http\ServerRequest;
use Fyre\Http\Uri;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Mock\Authenticators\MockAuthenticator;
use Tests\Mock\Entities\User;

use function class_uses;

final class AuthTest extends TestCase
{
    use ConnectionTrait;

    /**
     * @return array<string, array{string}>
     */
    public static function redirectUriProvider(): array
    {
        return [
            'without port' => ['https://example.com/private?tab=profile#details'],
            'with port' => ['http://localhost:8080/private?tab=profile#details'],
            'with default port' => ['https://example.com:443/private?tab=profile#details'],
            'without scheme' => ['//localhost:8080/private?tab=profile#details'],
            'relative' => ['/private?tab=profile#details'],
        ];
    }

    public function testAttempt(): void
    {
        $authUser = $this->auth->attempt('test@test.com', 'test');

        $this->assertInstanceOf(
            User::class,
            $authUser
        );

        $this->assertTrue($this->auth->isLoggedIn());
    }

    public function testAttemptInvalidPassword(): void
    {
        $authUser = $this->auth->attempt('test@test.com', 'invalid');

        $this->assertNull($authUser);
        $this->assertFalse($this->auth->isLoggedIn());
    }

    public function testAttemptInvalidUsername(): void
    {
        $authUser = $this->auth->attempt('invalid@test.com', 'any');

        $this->assertNull($authUser);
        $this->assertFalse($this->auth->isLoggedIn());
    }

    public function testAuthenticate(): void
    {
        $authenticator = $this->container->build(MockAuthenticator::class);
        $this->auth->addAuthenticator($authenticator);

        $request = $this->container->build(ServerRequest::class);

        $user = $this->auth->authenticate($request);

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame($user, $this->auth->user());
    }

    public function testAuthenticateDoesNotPersistStatelessIdentity(): void
    {
        $sessionAuthenticator = $this->container->build(SessionAuthenticator::class);
        $tokenAuthenticator = $this->container->build(TokenAuthenticator::class);

        $this->auth->addAuthenticator($sessionAuthenticator);
        $this->auth->addAuthenticator($tokenAuthenticator);

        $request = $this->container->build(ServerRequest::class, [
            'options' => [
                'headers' => [
                    'Authorization' => 'Bearer Ew7tqx8kH6QsNe8SS0tVT0BX2LIRVQyl',
                ],
            ],
        ]);

        $user = $this->auth->authenticate($request);

        $this->assertInstanceOf(User::class, $user);
        $this->assertNull($this->session->get('auth'));
    }

    public function testAuthenticatePersistsStatefulIdentity(): void
    {
        $sessionAuthenticator = $this->container->build(SessionAuthenticator::class);
        $authenticator = $this->container->build(MockAuthenticator::class);

        $this->auth->addAuthenticator($sessionAuthenticator);
        $this->auth->addAuthenticator($authenticator);

        $request = $this->container->build(ServerRequest::class);

        $user = $this->auth->authenticate($request);

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame(1, $this->session->get('auth'));
    }

    public function testAuthenticator(): void
    {
        $authenticator = $this->container->build(MockAuthenticator::class);

        $this->auth->addAuthenticator($authenticator);

        $this->assertSame(
            $authenticator,
            $this->auth->authenticator(MockAuthenticator::class)
        );
    }

    public function testAuthenticatorInvalid(): void
    {
        $this->assertNull(
            $this->auth->authenticator('invalid')
        );
    }

    public function testAuthenticatorKey(): void
    {
        $authenticator = $this->container->build(MockAuthenticator::class);

        $this->auth->addAuthenticator($authenticator, 'mock');

        $this->assertSame(
            $authenticator,
            $this->auth->authenticator('mock')
        );
    }

    public function testAuthenticators(): void
    {
        $authenticator = $this->container->build(MockAuthenticator::class);

        $this->auth->addAuthenticator($authenticator, 'mock');

        $this->assertArraysAreIdentical(
            [
                'mock' => $authenticator,
            ],
            $this->auth->authenticators()
        );
    }

    public function testDebug(): void
    {
        $this->assertContains(
            DebugTrait::class,
            class_uses(Auth::class)
        );
    }

    public function testGetLoginUrl(): void
    {
        $this->assertSame('/login', $this->auth->getLoginUrl());
    }

    public function testGetLoginUrlStringRedirect(): void
    {
        $this->assertSame(
            '/login?url=%2Fprivate',
            $this->auth->getLoginUrl('/private')
        );
    }

    #[DataProvider('redirectUriProvider')]
    public function testGetLoginUrlUriRedirect(string $value): void
    {
        $uri = new Uri($value);

        $this->assertSame(
            '/login?url=%2Fprivate%3Ftab%3Dprofile%23details',
            $this->auth->getLoginUrl($uri)
        );
        $this->assertSame($value, (string) $uri);
    }

    public function testImpersonate(): void
    {
        $authenticator = $this->container->build(SessionAuthenticator::class);
        $this->auth->addAuthenticator($authenticator);

        $this->session->set('auth', 1);
        $this->container->build(ServerRequest::class) |> $this->auth->authenticate(...);

        $user = $this->identifier->identify('impersonated@test.com');

        $this->assertInstanceOf(User::class, $user);

        $this->auth->impersonate($user);

        $this->assertSame(2, $this->session->get('auth'));
    }

    public function testImpersonateAlreadyImpersonating(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('A user is already being impersonated.');

        $authenticator = $this->container->build(SessionAuthenticator::class);
        $this->auth->addAuthenticator($authenticator);

        $this->session->set('auth', 1);
        $this->container->build(ServerRequest::class) |> $this->auth->authenticate(...);

        $user = $this->identifier->identify('impersonated@test.com');

        $this->assertInstanceOf(User::class, $user);

        $this->auth->impersonate($user);
        $this->auth->impersonate($user);
    }

    public function testImpersonateCurrentUser(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('A user cannot impersonate themselves.');

        $authenticator = $this->container->build(SessionAuthenticator::class);
        $this->auth->addAuthenticator($authenticator);

        $this->session->set('auth', 1);
        $this->container->build(ServerRequest::class) |> $this->auth->authenticate(...);

        $user = $this->auth->user();

        $this->assertInstanceOf(User::class, $user);

        $this->auth->impersonate($user);
    }

    public function testImpersonateLoggedOut(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('A user must be logged in before impersonating another user.');

        $authenticator = $this->container->build(SessionAuthenticator::class);
        $this->auth->addAuthenticator($authenticator);

        $user = $this->identifier->identify('impersonated@test.com');

        $this->assertInstanceOf(User::class, $user);

        $this->auth->impersonate($user);
    }

    public function testImpersonateStatelessIdentity(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('Impersonation requires a compatible authenticator.');

        $sessionAuthenticator = $this->container->build(SessionAuthenticator::class);
        $tokenAuthenticator = $this->container->build(TokenAuthenticator::class);

        $this->auth->addAuthenticator($sessionAuthenticator);
        $this->auth->addAuthenticator($tokenAuthenticator);

        $request = $this->container->build(ServerRequest::class, [
            'options' => [
                'headers' => [
                    'Authorization' => 'Bearer Ew7tqx8kH6QsNe8SS0tVT0BX2LIRVQyl',
                ],
            ],
        ]);

        $user = $this->auth->authenticate($request);

        $this->assertInstanceOf(User::class, $user);

        $targetUser = $this->identifier->identify('impersonated@test.com');

        $this->assertInstanceOf(User::class, $targetUser);

        $this->auth->impersonate($targetUser);
    }

    public function testImpersonateUnsupported(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('Impersonation requires a compatible authenticator.');

        $this->login();

        $user = $this->identifier->identify('impersonated@test.com');

        $this->assertInstanceOf(User::class, $user);

        $this->auth->impersonate($user);
    }

    public function testImpersonator(): void
    {
        $authenticator = $this->container->build(SessionAuthenticator::class);
        $this->auth->addAuthenticator($authenticator);

        $this->session->set('auth', 1);
        $this->container->build(ServerRequest::class) |> $this->auth->authenticate(...);

        $user = $this->identifier->identify('impersonated@test.com');

        $this->assertInstanceOf(User::class, $user);

        $this->auth->impersonate($user);

        $this->assertSame(1, $this->auth->impersonator()?->get('id'));
    }

    public function testImpersonatorNull(): void
    {
        $this->assertNull($this->auth->impersonator());
    }

    public function testIsImpersonating(): void
    {
        $authenticator = $this->container->build(SessionAuthenticator::class);
        $this->auth->addAuthenticator($authenticator);

        $this->session->set('auth', 1);
        $this->container->build(ServerRequest::class) |> $this->auth->authenticate(...);

        $user = $this->identifier->identify('impersonated@test.com');

        $this->assertInstanceOf(User::class, $user);

        $this->auth->impersonate($user);

        $this->assertTrue($this->auth->isImpersonating());
    }

    public function testIsImpersonatingFalse(): void
    {
        $this->assertFalse($this->auth->isImpersonating());
    }

    public function testIsLoggedIn(): void
    {
        $this->login();

        $this->assertTrue($this->auth->isLoggedIn());
    }

    public function testIsLoggedInFalse(): void
    {
        $this->assertFalse($this->auth->isLoggedIn());
    }

    public function testLogout(): void
    {
        $this->login();
        $this->auth->logout();

        $this->assertFalse($this->auth->isLoggedIn());
    }

    public function testLogoutStopsImpersonating(): void
    {
        $authenticator = $this->container->build(SessionAuthenticator::class);
        $this->auth->addAuthenticator($authenticator);

        $this->session->set('auth', 1);
        $this->container->build(ServerRequest::class) |> $this->auth->authenticate(...);

        $user = $this->identifier->identify('impersonated@test.com');

        $this->assertInstanceOf(User::class, $user);

        $this->auth->impersonate($user);
        $this->auth->logout();

        $this->assertFalse($this->auth->isImpersonating());
    }

    public function testMacro(): void
    {
        $this->assertContains(
            MacroTrait::class,
            class_uses(Auth::class)
        );
    }

    public function testStopImpersonating(): void
    {
        $authenticator = $this->container->build(SessionAuthenticator::class);
        $this->auth->addAuthenticator($authenticator);

        $this->session->set('auth', 1);
        $this->container->build(ServerRequest::class) |> $this->auth->authenticate(...);

        $user = $this->identifier->identify('impersonated@test.com');

        $this->assertInstanceOf(User::class, $user);

        $this->auth->impersonate($user);
        $this->auth->stopImpersonating();

        $this->assertSame(1, $this->session->get('auth'));
    }

    public function testUser(): void
    {
        $this->login();

        $this->assertInstanceOf(
            User::class,
            $this->auth->user()
        );
    }

    public function testUserNull(): void
    {
        $this->assertNull(
            $this->auth->user()
        );
    }
}
