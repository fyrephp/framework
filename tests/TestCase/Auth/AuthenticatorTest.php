<?php
declare(strict_types=1);

namespace Tests\TestCase\Auth;

use Fyre\Auth\Auth;
use Fyre\Auth\Authenticator;
use Fyre\Auth\Authenticators\CookieAuthenticator;
use Fyre\Auth\Authenticators\SessionAuthenticator;
use Fyre\Auth\Authenticators\TokenAuthenticator;
use Fyre\Core\Config;
use Fyre\Core\Traits\DebugTrait;
use Fyre\Core\Traits\MacroTrait;
use Fyre\Http\ClientResponse;
use Fyre\Http\Cookie\Cookie;
use Fyre\Http\MiddlewareQueue;
use Fyre\Http\RequestHandler;
use Fyre\Http\ServerRequest;
use Fyre\ORM\Queries\SelectQuery;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Mock\Authenticators\MockAuthenticator;
use Tests\Mock\Entities\User;

use function class_uses;
use function hash;
use function json_decode;
use function json_encode;
use function password_hash;
use function password_verify;
use function rawurldecode;
use function rawurlencode;
use function str_repeat;

use const PASSWORD_ARGON2I;
use const PASSWORD_ARGON2ID;
use const PASSWORD_BCRYPT;
use const PASSWORD_BCRYPT_DEFAULT_COST;
use const PASSWORD_DEFAULT;

final class AuthenticatorTest extends TestCase
{
    use ConnectionTrait;

    /**
     * @return array<string, array{string|null, string, string, string}>
     */
    public static function cookieFieldChangeProvider(): array
    {
        $cases = [];
        $longIdentifier = str_repeat('a', 80).'@test.com';

        foreach ([null, 'secret'] as $salt) {
            $prefix = $salt === null ? 'unsalted' : 'salted';

            $cases[$prefix.' password with long identifier'] = [$salt, $longIdentifier, 'password', 'changed-password-hash'];
            $cases[$prefix.' password suffix'] = [$salt, 'test@test.com', 'password', str_repeat('a', 80).'changed'];
            $cases[$prefix.' identifier suffix'] = [$salt, $longIdentifier, 'email', str_repeat('a', 80).'@changed.com'];
        }

        return $cases;
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function cookieSaltChangeProvider(): array
    {
        return [
            'changed salt' => ['changed-secret'],
            'removed salt' => [null],
        ];
    }

    /**
     * @return array<string, array{array{}|string}>
     */
    public static function invalidCookieDataProvider(): array
    {
        return [
            'JSON string' => [rawurlencode('"invalid"')],
            'nested arrays' => [rawurlencode('[[],[]]')],
            'array cookie' => [[]],
        ];
    }

    /**
     * @return array<string, array{string, array<string, int>}>
     */
    public static function invalidCookieHashOptionsProvider(): array
    {
        return [
            'lower bcrypt cost' => [PASSWORD_BCRYPT, ['cost' => 4]],
            'higher bcrypt cost' => [PASSWORD_BCRYPT, ['cost' => PASSWORD_BCRYPT_DEFAULT_COST + 1]],
            'argon2i' => [PASSWORD_ARGON2I, ['memory_cost' => 8192, 'time_cost' => 1, 'threads' => 1]],
            'argon2id' => [PASSWORD_ARGON2ID, ['memory_cost' => 8192, 'time_cost' => 1, 'threads' => 1]],
        ];
    }

    /**
     * @return array<string, array{array{password?: false|string|null}}>
     */
    public static function invalidCookiePasswordFieldsProvider(): array
    {
        return [
            'missing' => [[]],
            'null' => [['password' => null]],
            'empty' => [['password' => '']],
            'non-string' => [['password' => false]],
        ];
    }

    public function testConstructAuthenticatorClassKey(): void
    {
        $this->container->use(Config::class)->set('Auth.authenticators', [
            [
                'className' => MockAuthenticator::class,
            ],
        ]);

        $auth = $this->container->build(Auth::class);

        $this->assertInstanceOf(
            MockAuthenticator::class,
            $auth->authenticator(MockAuthenticator::class)
        );
    }

    public function testConstructAuthenticatorKey(): void
    {
        $this->container->use(Config::class)->set('Auth.authenticators', [
            'mock' => [
                'className' => MockAuthenticator::class,
            ],
        ]);

        $auth = $this->container->build(Auth::class);

        $this->assertInstanceOf(
            MockAuthenticator::class,
            $auth->authenticator('mock')
        );
    }

    public function testConstructInvalidAuthenticator(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Authenticator `Invalid` must extend `Fyre\Auth\Authenticator`.');

        $this->container->use(Config::class)->set('Auth.authenticators', [
            [
                'className' => 'Invalid',
            ],
        ]);

        $this->container->build(Auth::class);
    }

    public function testCookieAuthenticator(): void
    {
        $authenticator = $this->container->build(CookieAuthenticator::class);
        $this->auth->addAuthenticator($authenticator);

        $queue = new MiddlewareQueue([
            'auth',
        ]);

        $handler = $this->container->build(RequestHandler::class, ['queue' => $queue]);

        $authUser = $this->identifier->identify('test@test.com');

        $this->assertInstanceOf(
            User::class,
            $authUser
        );

        $tokenHash = password_hash(hash('sha256', 'test@test.com'.$authUser->password), PASSWORD_DEFAULT);
        $auth = (string) json_encode(['test@test.com', $tokenHash]) |> rawurlencode(...);

        $request = $this->container->build(ServerRequest::class, [
            'options' => [
                'cookies' => [
                    'auth' => $auth,
                ],
            ],
        ]);

        $response = $handler->handle($request);

        $this->assertInstanceOf(
            ClientResponse::class,
            $response
        );

        $this->assertTrue($this->auth->isLoggedIn());
    }

    #[DataProvider('cookieFieldChangeProvider')]
    public function testCookieAuthenticatorInvalidatedByFieldChange(string|null $salt, string $email, string $field, string $value): void
    {
        $authUser = $this->identifier->identify('test@test.com');

        $this->assertInstanceOf(
            User::class,
            $authUser
        );

        $authUser->email = $email;
        $authUser->password = str_repeat('a', 80).'original';

        $this->identifier->getModel()->save($authUser);

        $authenticator = $this->container->build(CookieAuthenticator::class, [
            'options' => [
                'salt' => $salt,
            ],
        ]);
        $authenticator->login($authUser, true);

        $response = $authenticator->beforeResponse(new ClientResponse());

        [$cookieString] = $response->getHeader('Set-Cookie');

        $cookie = Cookie::createFromHeaderString($cookieString);

        $request = $this->container->build(ServerRequest::class, [
            'options' => [
                'cookies' => [
                    'auth' => $cookie->getValue(),
                ],
            ],
        ]);

        $this->assertInstanceOf(
            User::class,
            $authenticator->authenticate($request)
        );

        $authUser->set($field, $value);

        $this->identifier->getModel()->save($authUser);

        if ($field === 'email') {
            $data = $cookie->getValue() |> rawurldecode(...);
            [, $tokenHash] = json_decode($data, true);

            // Keep the old token hash while allowing the changed identifier to resolve.
            $auth = (string) json_encode([$value, $tokenHash]) |> rawurlencode(...);
            $request = $request->withCookieParams(['auth' => $auth]);
        }

        $this->assertNull(
            $authenticator->authenticate($request)
        );

        $response = $authenticator->beforeResponse(new ClientResponse());

        [$cookieString] = $response->getHeader('Set-Cookie');

        $cookie = Cookie::createFromHeaderString($cookieString);

        $this->assertTrue(
            $cookie->isExpired()
        );
    }

    #[DataProvider('cookieSaltChangeProvider')]
    public function testCookieAuthenticatorInvalidatedBySaltChange(string|null $salt): void
    {
        $authUser = $this->identifier->identify('test@test.com');

        $this->assertInstanceOf(
            User::class,
            $authUser
        );

        $authUser->password = str_repeat('a', 80).'original';

        $this->identifier->getModel()->save($authUser);

        $authenticator = $this->container->build(CookieAuthenticator::class, [
            'options' => [
                'salt' => 'original-secret',
            ],
        ]);
        $authenticator->login($authUser, true);

        $response = $authenticator->beforeResponse(new ClientResponse());

        [$cookieString] = $response->getHeader('Set-Cookie');

        $cookie = Cookie::createFromHeaderString($cookieString);

        $request = $this->container->build(ServerRequest::class, [
            'options' => [
                'cookies' => [
                    'auth' => $cookie->getValue(),
                ],
            ],
        ]);

        $this->assertInstanceOf(
            User::class,
            $authenticator->authenticate($request)
        );

        $authenticator = $this->container->build(CookieAuthenticator::class, [
            'options' => [
                'salt' => $salt,
            ],
        ]);

        $this->assertNull(
            $authenticator->authenticate($request)
        );
    }

    /**
     * @param array{}|string $value
     */
    #[DataProvider('invalidCookieDataProvider')]
    public function testCookieAuthenticatorInvalidData(array|string $value): void
    {
        $authenticator = $this->container->build(CookieAuthenticator::class);

        $request = $this->container->build(ServerRequest::class, [
            'options' => [
                'cookies' => [
                    'auth' => $value,
                ],
            ],
        ]);

        $this->assertNull(
            $authenticator->authenticate($request)
        );
    }

    /**
     * @param array{}|string $value
     */
    #[DataProvider('invalidCookieDataProvider')]
    public function testCookieAuthenticatorInvalidDataExpiresCookie(array|string $value): void
    {
        $authenticator = $this->container->build(CookieAuthenticator::class);

        $request = $this->container->build(ServerRequest::class, [
            'options' => [
                'cookies' => [
                    'auth' => $value,
                ],
            ],
        ]);

        $authenticator->authenticate($request);
        $response = $authenticator->beforeResponse(new ClientResponse());

        [$cookieString] = $response->getHeader('Set-Cookie');

        $cookie = Cookie::createFromHeaderString($cookieString);

        $this->assertTrue(
            $cookie->isExpired()
        );
    }

    /**
     * @param array<string, int> $options
     */
    #[DataProvider('invalidCookieHashOptionsProvider')]
    public function testCookieAuthenticatorInvalidHashOptions(string $algorithm, array $options): void
    {
        $authUser = $this->identifier->identify('test@test.com');

        $this->assertInstanceOf(
            User::class,
            $authUser
        );

        $tokenHash = password_hash(hash('sha256', 'test@test.com'.$authUser->password), $algorithm, $options);
        $auth = (string) json_encode(['test@test.com', $tokenHash]) |> rawurlencode(...);

        $authenticator = $this->container->build(CookieAuthenticator::class);

        $request = $this->container->build(ServerRequest::class, [
            'options' => [
                'cookies' => [
                    'auth' => $auth,
                ],
            ],
        ]);

        $this->assertNull(
            $authenticator->authenticate($request)
        );
    }

    public function testCookieAuthenticatorLogin(): void
    {
        $authenticator = $this->container->build(CookieAuthenticator::class);
        $this->auth->addAuthenticator($authenticator);

        $queue = new MiddlewareQueue([
            'auth',
        ]);

        $handler = $this->container->build(RequestHandler::class, ['queue' => $queue]);

        $request = $this->container->build(ServerRequest::class);

        $this->auth->attempt('test@test.com', 'test');

        $response = $handler->handle($request);

        $this->assertInstanceOf(
            ClientResponse::class,
            $response
        );

        $this->assertTrue($this->auth->isLoggedIn());

        $this->assertArraysAreIdentical(
            [],
            $response->getHeader('Set-Cookie')
        );
    }

    public function testCookieAuthenticatorLoginRemember(): void
    {
        $authenticator = $this->container->build(CookieAuthenticator::class);
        $this->auth->addAuthenticator($authenticator);

        $queue = new MiddlewareQueue([
            'auth',
        ]);

        $handler = $this->container->build(RequestHandler::class, ['queue' => $queue]);

        $request = $this->container->build(ServerRequest::class);

        $this->auth->attempt('test@test.com', 'test', true);

        $response = $handler->handle($request);

        $this->assertInstanceOf(
            ClientResponse::class,
            $response
        );

        $this->assertTrue($this->auth->isLoggedIn());

        $authUser = $this->auth->user();

        $this->assertInstanceOf(
            User::class,
            $authUser
        );

        [$cookieString] = $response->getHeader('Set-Cookie');

        $cookie = Cookie::createFromHeaderString($cookieString);

        $this->assertSame(
            'auth',
            $cookie->getName()
        );

        $value = $cookie->getValue() |> rawurldecode(...);
        $data = json_decode($value, true);

        $this->assertCount(2, $data);

        [$identifier, $tokenHash] = $data;

        $token = hash('sha256', 'test@test.com'.$authUser->password);

        $this->assertTrue(
            password_verify($token, $tokenHash)
        );

        $this->assertSame('auth', $cookie->getName());
        $this->assertFalse($cookie->isExpired());
    }

    public function testCookieAuthenticatorLoginRememberUser(): void
    {
        $authenticator = $this->container->build(CookieAuthenticator::class);

        $user = $this->identifier->identify('test@test.com');
        $otherUser = $this->identifier->identify('impersonated@test.com');

        $this->assertInstanceOf(User::class, $user);
        $this->assertInstanceOf(User::class, $otherUser);

        $authenticator->login($user, true);

        $response = $authenticator->beforeResponse(new ClientResponse(), $otherUser);

        [$cookieString] = $response->getHeader('Set-Cookie');

        $cookie = Cookie::createFromHeaderString($cookieString);
        $data = json_decode($cookie->getValue() |> rawurldecode(...), true);

        $this->assertSame('test@test.com', $data[0]);
    }

    /**
     * @param array{password?: false|string|null} $fields
     */
    #[DataProvider('invalidCookiePasswordFieldsProvider')]
    public function testCookieAuthenticatorLoginRememberWithoutPassword(array $fields): void
    {
        $authenticator = $this->container->build(CookieAuthenticator::class);
        $this->auth->addAuthenticator($authenticator);

        $user = $this->identifier->identify('test@test.com');
        $this->assertInstanceOf(User::class, $user);

        if ($fields === []) {
            $user->unset('password');
        } else {
            $user->set('password', $fields['password']);
        }

        $this->auth->login($user, true);

        $queue = new MiddlewareQueue(['auth']);
        $handler = $this->container->build(RequestHandler::class, ['queue' => $queue]);
        $request = $this->container->build(ServerRequest::class);
        $response = $handler->handle($request);

        [$cookieString] = $response->getHeader('Set-Cookie');
        $cookie = Cookie::createFromHeaderString($cookieString);

        $this->assertSame('auth', $cookie->getName());
        $this->assertSame('', $cookie->getValue());
        $this->assertTrue($cookie->isExpired());
    }

    public function testCookieAuthenticatorLogout(): void
    {
        $authenticator = $this->container->build(CookieAuthenticator::class);
        $this->auth->addAuthenticator($authenticator);

        $queue = new MiddlewareQueue([
            'auth',
        ]);

        $handler = $this->container->build(RequestHandler::class, ['queue' => $queue]);

        $request = $this->container->build(ServerRequest::class);

        $this->auth->attempt('test@test.com', 'test', true);
        $this->auth->logout();

        $response = $handler->handle($request);

        $this->assertInstanceOf(
            ClientResponse::class,
            $response
        );

        $this->assertFalse($this->auth->isLoggedIn());

        [$cookieString] = $response->getHeader('Set-Cookie');

        $cookie = Cookie::createFromHeaderString($cookieString);

        $this->assertSame(
            'auth',
            $cookie->getName()
        );

        $this->assertSame('', $cookie->getValue());
        $this->assertTrue($cookie->isExpired());
    }

    public function testCookieAuthenticatorPersistsSession(): void
    {
        $sessionAuthenticator = $this->container->build(SessionAuthenticator::class);
        $cookieAuthenticator = $this->container->build(CookieAuthenticator::class);

        $this->auth->addAuthenticator($sessionAuthenticator);
        $this->auth->addAuthenticator($cookieAuthenticator);

        $authUser = $this->identifier->identify('test@test.com');

        $this->assertInstanceOf(User::class, $authUser);

        $tokenHash = password_hash(hash('sha256', 'test@test.com'.$authUser->password), PASSWORD_DEFAULT);
        $auth = (string) json_encode(['test@test.com', $tokenHash]) |> rawurlencode(...);

        $request = $this->container->build(ServerRequest::class, [
            'options' => [
                'cookies' => [
                    'auth' => $auth,
                ],
            ],
        ]);

        $user = $this->auth->authenticate($request);

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame(1, $this->session->get('auth'));
    }

    public function testCookieAuthenticatorRequiresSelectedPasswordField(): void
    {
        $user = $this->identifier->identify('test@test.com');
        $this->assertInstanceOf(User::class, $user);

        $options = ['passwordField' => 'token', 'salt' => 'secret'];
        $authenticator = $this->container->build(CookieAuthenticator::class, ['options' => $options]);
        $authenticator->login($user, true);

        $response = $authenticator->beforeResponse(new ClientResponse());
        [$cookieString] = $response->getHeader('Set-Cookie');
        $cookie = Cookie::createFromHeaderString($cookieString);
        $request = $this->container->build(ServerRequest::class)->withCookieParams(['auth' => $cookie->getValue()]);

        $this->assertInstanceOf(User::class, $authenticator->authenticate($request));

        $this->container->use(Config::class)->set(
            'Auth.identifier.queryCallback',
            static fn(SelectQuery $query): SelectQuery => $query->select(['id', 'email', 'password'])
        );
        $authenticator = $this->container->build(CookieAuthenticator::class, [
            'auth' => $this->container->build(Auth::class),
            'options' => $options,
        ]);

        $this->assertNull($authenticator->authenticate($request));

        $response = $authenticator->beforeResponse(new ClientResponse());
        [$cookieString] = $response->getHeader('Set-Cookie');
        $cookie = Cookie::createFromHeaderString($cookieString);
        $this->assertTrue($cookie->isExpired());
    }

    public function testDebug(): void
    {
        $this->assertContains(
            DebugTrait::class,
            class_uses(Authenticator::class)
        );
    }

    public function testDebugCookieAuthenticator(): void
    {
        $data = $this->container->build(CookieAuthenticator::class, [
            'options' => [
                'salt' => 'l2wyQow3eTwQeTWcfZnlgU8FnbiWljpGjQvNP2pL',
            ],
        ])->__debugInfo();

        $this->assertArraysAreIdentical(
            [
                '[class]' => CookieAuthenticator::class,
                'auth' => '[Fyre\Auth\Auth]',
                'config' => [
                    'cookieName' => 'auth',
                    'cookieOptions' => [
                        'httpOnly' => true,
                    ],
                    'identifierField' => 'email',
                    'passwordField' => 'password',
                    'salt' => '[*****]',
                ],
                'cookieUser' => null,
                'sendCookie' => null,
            ],
            $data
        );
    }

    public function testMacro(): void
    {
        $this->assertContains(
            MacroTrait::class,
            class_uses(Authenticator::class)
        );
    }

    public function testSessionAuthenticator(): void
    {
        $authenticator = $this->container->build(SessionAuthenticator::class);
        $this->auth->addAuthenticator($authenticator);

        $queue = new MiddlewareQueue([
            'auth',
        ]);

        $handler = $this->container->build(RequestHandler::class, ['queue' => $queue]);

        $request = $this->container->build(ServerRequest::class);

        $this->session->set('auth', 1);

        $response = $handler->handle($request);

        $this->assertInstanceOf(
            ClientResponse::class,
            $response
        );

        $this->assertTrue($this->auth->isLoggedIn());
    }

    public function testSessionAuthenticatorAppliesIdentifierQueryCallback(): void
    {
        $this->container->use(Config::class)->set(
            'Auth.identifier.queryCallback',
            static fn(SelectQuery $query): SelectQuery => $query->where(['Users.email IS NOT' => null])
        );
        $authenticator = $this->container->build(SessionAuthenticator::class, [
            'auth' => $this->container->build(Auth::class),
        ]);
        $this->session->set('auth', 1);
        $request = $this->container->build(ServerRequest::class);

        $user = $authenticator->authenticate($request);
        $this->assertInstanceOf(User::class, $user);
        $this->assertSame(1, $user->id);

        $user->email = null;
        $this->identifier->getModel()->save($user);

        $this->assertNull($authenticator->authenticate($request));
    }

    public function testSessionAuthenticatorInvalidImpersonatorSessionKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Session keys for authentication and impersonation must be different.');

        $this->container->build(SessionAuthenticator::class, [
            'options' => [
                'impersonatorSessionKey' => 'auth',
            ],
        ]);
    }

    public function testSessionAuthenticatorLogin(): void
    {
        $authenticator = $this->container->build(SessionAuthenticator::class);
        $this->auth->addAuthenticator($authenticator);

        $queue = new MiddlewareQueue([
            'auth',
        ]);

        $handler = $this->container->build(RequestHandler::class, ['queue' => $queue]);

        $request = $this->container->build(ServerRequest::class);

        $this->session->set('auth', 2);

        $this->auth->attempt('test@test.com', 'test');

        $this->assertSame(
            1,
            $this->session->get('auth')
        );

        $response = $handler->handle($request);

        $this->assertInstanceOf(
            ClientResponse::class,
            $response
        );

        $this->assertTrue($this->auth->isLoggedIn());

        $this->assertSame(
            1,
            $this->session->get('auth')
        );
    }

    public function testSessionAuthenticatorLogout(): void
    {
        $authenticator = $this->container->build(SessionAuthenticator::class);
        $this->auth->addAuthenticator($authenticator);

        $this->auth->attempt('test@test.com', 'test');
        $this->auth->logout();

        $this->assertFalse($this->auth->isLoggedIn());

        $this->assertNull(
            $this->session->get('auth')
        );
    }

    public function testTokenAuthenticator(): void
    {
        $authenticator = $this->container->build(TokenAuthenticator::class);
        $this->auth->addAuthenticator($authenticator);

        $queue = new MiddlewareQueue([
            'auth',
        ]);

        $handler = $this->container->build(RequestHandler::class, ['queue' => $queue]);

        $request = $this->container->build(ServerRequest::class, [
            'options' => [
                'headers' => [
                    'Authorization' => 'Bearer Ew7tqx8kH6QsNe8SS0tVT0BX2LIRVQyl',
                ],
            ],
        ]);

        $response = $handler->handle($request);

        $this->assertInstanceOf(
            ClientResponse::class,
            $response
        );

        $this->assertTrue($this->auth->isLoggedIn());
    }

    public function testTokenAuthenticatorAppliesIdentifierQueryCallback(): void
    {
        $this->container->use(Config::class)->set(
            'Auth.identifier.queryCallback',
            static fn(SelectQuery $query): SelectQuery => $query->where(['Users.email IS NOT' => null])
        );
        $authenticator = $this->container->build(TokenAuthenticator::class, [
            'auth' => $this->container->build(Auth::class),
        ]);
        $request = $this->container->build(ServerRequest::class, [
            'options' => [
                'headers' => [
                    'Authorization' => 'Bearer Ew7tqx8kH6QsNe8SS0tVT0BX2LIRVQyl',
                ],
            ],
        ]);

        $user = $authenticator->authenticate($request);
        $this->assertInstanceOf(User::class, $user);
        $this->assertSame(1, $user->id);

        $user->email = null;
        $this->identifier->getModel()->save($user);

        $this->assertNull($authenticator->authenticate($request));
    }

    public function testTokenAuthenticatorQuery(): void
    {
        $authenticator = $this->container->build(TokenAuthenticator::class, [
            'options' => [
                'tokenQuery' => 'token',
            ],
        ]);
        $this->auth->addAuthenticator($authenticator);

        $queue = new MiddlewareQueue([
            'auth',
        ]);

        $handler = $this->container->build(RequestHandler::class, ['queue' => $queue]);

        $request = $this->container->build(ServerRequest::class, [
            'options' => [
                'get' => [
                    'token' => 'Ew7tqx8kH6QsNe8SS0tVT0BX2LIRVQyl',
                ],
            ],
        ]);

        $response = $handler->handle($request);

        $this->assertInstanceOf(
            ClientResponse::class,
            $response
        );

        $this->assertTrue($this->auth->isLoggedIn());
    }
}
