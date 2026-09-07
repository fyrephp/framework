<?php
declare(strict_types=1);

namespace Tests\TestCase\Security;

use Closure;
use Fyre\Core\Config;
use Fyre\Core\Container;
use Fyre\Core\Traits\DebugTrait;
use Fyre\Http\ClientResponse;
use Fyre\Http\Cookie\Cookie;
use Fyre\Http\MiddlewareQueue;
use Fyre\Http\RequestHandler;
use Fyre\Http\ServerRequest;
use Fyre\Security\CsrfProtection;
use Fyre\Security\Exceptions\CsrfTokenException;
use Fyre\Security\Middleware\CsrfProtectionMiddleware;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

use function base64_encode;
use function class_uses;

final class CsrfProtectionMiddlewareTest extends TestCase
{
    protected Container $container;

    /**
     * @return array<string, array{string}>
     */
    public static function invalidCookieProvider(): array
    {
        return [
            'empty' => [''],
            'whitespace' => [" \t\r\n"],
            'invalid base64' => ['%'],
        ];
    }

    /**
     * @return array<string, array{Closure(CsrfProtection): array<string, mixed>}>
     */
    public static function invalidCookieRequestProvider(): array
    {
        return [
            'empty cookie with form token' => [
                static fn(CsrfProtection $csrfProtection): array => [
                    'method' => 'POST',
                    'cookies' => [
                        'CsrfToken' => '',
                    ],
                    'data' => [
                        'csrf_token' => $csrfProtection->getFormToken(),
                    ],
                ],
            ],
            'short decoded cookie with header token' => [
                static fn(CsrfProtection $csrfProtection): array => [
                    'method' => 'POST',
                    'headers' => [
                        'Csrf-Token' => $csrfProtection->getFormToken(),
                    ],
                    'cookies' => [
                        'CsrfToken' => base64_encode('short'),
                    ],
                ],
            ],
            'cookie without signature with header token' => [
                static fn(CsrfProtection $csrfProtection): array => [
                    'method' => 'POST',
                    'headers' => [
                        'Csrf-Token' => $csrfProtection->getFormToken(),
                    ],
                    'cookies' => [
                        'CsrfToken' => base64_encode('0123456789abcdef'),
                    ],
                ],
            ],
            'tampered cookie with header token' => [
                static fn(CsrfProtection $csrfProtection): array => [
                    'method' => 'POST',
                    'headers' => [
                        'Csrf-Token' => $csrfProtection->getFormToken(),
                    ],
                    'cookies' => [
                        'CsrfToken' => $csrfProtection->getCookieToken().'1',
                    ],
                ],
            ],
            'array cookie with header token' => [
                static fn(CsrfProtection $csrfProtection): array => [
                    'method' => 'POST',
                    'headers' => [
                        'Csrf-Token' => $csrfProtection->getFormToken(),
                    ],
                    'cookies' => [
                        'CsrfToken' => [],
                    ],
                ],
            ],
            'missing cookie with header token' => [
                static fn(CsrfProtection $csrfProtection): array => [
                    'method' => 'POST',
                    'headers' => [
                        'Csrf-Token' => $csrfProtection->getFormToken(),
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, array{Closure(CsrfProtection): array<string, mixed>}>
     */
    public static function invalidSubmittedTokenProvider(): array
    {
        return [
            'invalid base64 form token' => [
                static fn(CsrfProtection $csrfProtection): array => [
                    'method' => 'POST',
                    'cookies' => [
                        'CsrfToken' => $csrfProtection->getCookieToken(),
                    ],
                    'data' => [
                        'csrf_token' => $csrfProtection->getFormToken().'%',
                    ],
                ],
            ],
            'invalid base64 header token' => [
                static fn(CsrfProtection $csrfProtection): array => [
                    'method' => 'POST',
                    'headers' => [
                        'Csrf-Token' => $csrfProtection->getFormToken().'%',
                    ],
                    'cookies' => [
                        'CsrfToken' => $csrfProtection->getCookieToken(),
                    ],
                ],
            ],
            'array form token' => [
                static fn(CsrfProtection $csrfProtection): array => [
                    'method' => 'POST',
                    'cookies' => [
                        'CsrfToken' => $csrfProtection->getCookieToken(),
                    ],
                    'data' => [
                        'csrf_token' => [],
                    ],
                ],
            ],
            'malformed header token' => [
                static fn(CsrfProtection $csrfProtection): array => [
                    'method' => 'POST',
                    'headers' => [
                        'Csrf-Token' => 'YQ==',
                    ],
                    'cookies' => [
                        'CsrfToken' => $csrfProtection->getCookieToken(),
                    ],
                ],
            ],
            'missing token' => [
                static fn(CsrfProtection $csrfProtection): array => [
                    'method' => 'POST',
                    'cookies' => [
                        'CsrfToken' => $csrfProtection->getCookieToken(),
                    ],
                ],
            ],
        ];
    }

    public function testDebug(): void
    {
        $this->assertContains(
            DebugTrait::class,
            class_uses(CsrfProtectionMiddleware::class)
        );
    }

    public function testFormTokenHeader(): void
    {
        $csrfProtection = $this->container->use(CsrfProtection::class);
        $middleware = $this->container->build(CsrfProtectionMiddleware::class);

        $queue = new MiddlewareQueue();
        $queue->add($middleware);

        $handler = $this->container->build(RequestHandler::class, ['queue' => $queue]);
        $request = $this->container->build(ServerRequest::class, [
            'options' => [
                'method' => 'POST',
                'headers' => [
                    'Csrf-Token' => $csrfProtection->getFormToken(),
                ],
                'cookies' => [
                    'CsrfToken' => $csrfProtection->getCookieToken(),
                ],
            ],
        ]);

        $response = $handler->handle($request);

        $this->assertInstanceOf(
            ClientResponse::class,
            $response
        );
    }

    public function testFormTokenInvalid(): void
    {
        $this->expectException(CsrfTokenException::class);
        $this->expectExceptionMessageIs('CSRF Token Mismatch');

        $csrfProtection = $this->container->use(CsrfProtection::class);
        $otherCsrfProtection = $this->container->build(CsrfProtection::class);
        $middleware = $this->container->build(CsrfProtectionMiddleware::class);

        $queue = new MiddlewareQueue();
        $queue->add($middleware);

        $handler = $this->container->build(RequestHandler::class, ['queue' => $queue]);
        $request = $this->container->build(ServerRequest::class, [
            'options' => [
                'method' => 'POST',
                'cookies' => [
                    'CsrfToken' => $csrfProtection->getCookieToken(),
                ],
                'data' => [
                    'csrf_token' => $otherCsrfProtection->getFormToken(),
                ],
            ],
        ]);

        $handler->handle($request);
    }

    public function testFormTokenPost(): void
    {
        $csrfProtection = $this->container->use(CsrfProtection::class);
        $middleware = $this->container->build(CsrfProtectionMiddleware::class);

        $queue = new MiddlewareQueue();
        $queue->add($middleware);

        $handler = $this->container->build(RequestHandler::class, ['queue' => $queue]);
        $request = $this->container->build(ServerRequest::class, [
            'options' => [
                'method' => 'POST',
                'cookies' => [
                    'CsrfToken' => $csrfProtection->getCookieToken(),
                ],
                'data' => [
                    'csrf_token' => $csrfProtection->getFormToken(),
                ],
            ],
        ]);

        $response = $handler->handle($request);

        $this->assertInstanceOf(
            ClientResponse::class,
            $response
        );
    }

    public function testFormTokenPostRemovesToken(): void
    {
        $csrfProtection = $this->container->use(CsrfProtection::class);
        $middleware = $this->container->build(CsrfProtectionMiddleware::class);

        $queue = new MiddlewareQueue();
        $queue->add($middleware);

        $handler = $this->container->build(RequestHandler::class, ['queue' => $queue]);
        $request = $this->container->build(ServerRequest::class, [
            'options' => [
                'method' => 'POST',
                'cookies' => [
                    'CsrfToken' => $csrfProtection->getCookieToken(),
                ],
                'data' => [
                    'csrf_token' => $csrfProtection->getFormToken(),
                    'title' => 'Test',
                ],
            ],
        ]);

        $handler->handle($request);

        $request = $this->container->use(ServerRequest::class);

        $this->assertSame(
            ['title' => 'Test'],
            $request->getParsedBody()
        );
    }

    public function testGet(): void
    {
        $middleware = $this->container->build(CsrfProtectionMiddleware::class);

        $queue = new MiddlewareQueue();
        $queue->add($middleware);

        $handler = $this->container->build(RequestHandler::class, ['queue' => $queue]);
        $request = $this->container->build(ServerRequest::class);

        $response = $handler->handle($request);

        $this->assertInstanceOf(
            ClientResponse::class,
            $response
        );
    }

    public function testGetAttachesCsrfProtection(): void
    {
        $csrfProtection = $this->container->use(CsrfProtection::class);
        $middleware = $this->container->build(CsrfProtectionMiddleware::class);

        $queue = new MiddlewareQueue();
        $queue->add($middleware);

        $handler = $this->container->build(RequestHandler::class, ['queue' => $queue]);
        $request = $this->container->build(ServerRequest::class);

        $handler->handle($request);

        $request = $this->container->use(ServerRequest::class);

        $this->assertSame(
            $csrfProtection,
            $request->getAttribute('csrf')
        );
    }

    public function testGetCreatesCookie(): void
    {
        $csrfProtection = $this->container->use(CsrfProtection::class);
        $middleware = $this->container->build(CsrfProtectionMiddleware::class);

        $queue = new MiddlewareQueue();
        $queue->add($middleware);

        $handler = $this->container->build(RequestHandler::class, ['queue' => $queue]);
        $request = $this->container->build(ServerRequest::class);

        $response = $handler->handle($request);

        [$cookieString] = $response->getHeader('Set-Cookie');

        $cookie = Cookie::createFromHeaderString($cookieString);

        $this->assertSame(
            'CsrfToken',
            $cookie->getName()
        );

        $this->assertSame(
            $csrfProtection->getCookieToken(),
            $cookie->getValue()
        );
    }

    #[DataProvider('invalidCookieProvider')]
    public function testGetInvalidCookie(string $cookie): void
    {
        $csrfProtection = $this->container->use(CsrfProtection::class);
        $middleware = $this->container->build(CsrfProtectionMiddleware::class);

        $queue = new MiddlewareQueue();
        $queue->add($middleware);

        $handler = $this->container->build(RequestHandler::class, ['queue' => $queue]);
        $request = $this->container->build(ServerRequest::class, [
            'options' => [
                'cookies' => [
                    'CsrfToken' => $cookie,
                ],
            ],
        ]);

        $response = $handler->handle($request);

        $this->assertInstanceOf(
            ClientResponse::class,
            $response
        );

        $this->assertNull(
            $csrfProtection->getFormToken()
        );
    }

    /**
     * @param Closure(CsrfProtection): array<string, mixed> $factory
     */
    #[DataProvider('invalidCookieRequestProvider')]
    public function testRejectInvalidCookie(Closure $factory): void
    {
        $this->expectException(CsrfTokenException::class);
        $this->expectExceptionMessageIs('CSRF Token Mismatch');

        $csrfProtection = $this->container->use(CsrfProtection::class);
        $middleware = $this->container->build(CsrfProtectionMiddleware::class);

        $queue = new MiddlewareQueue();
        $queue->add($middleware);

        $handler = $this->container->build(RequestHandler::class, ['queue' => $queue]);
        $request = $this->container->build(ServerRequest::class, [
            'options' => $factory($csrfProtection),
        ]);

        $handler->handle($request);
    }

    /**
     * @param Closure(CsrfProtection): array<string, mixed> $factory
     */
    #[DataProvider('invalidSubmittedTokenProvider')]
    public function testRejectInvalidSubmittedToken(Closure $factory): void
    {
        $this->expectException(CsrfTokenException::class);
        $this->expectExceptionMessageIs('CSRF Token Mismatch');

        $csrfProtection = $this->container->use(CsrfProtection::class);
        $middleware = $this->container->build(CsrfProtectionMiddleware::class);

        $queue = new MiddlewareQueue();
        $queue->add($middleware);

        $handler = $this->container->build(RequestHandler::class, ['queue' => $queue]);
        $request = $this->container->build(ServerRequest::class, [
            'options' => $factory($csrfProtection),
        ]);

        $handler->handle($request);
    }

    public function testSkipCheck(): void
    {
        $this->container->use(Config::class)->set('Csrf.skipCheck', function(ServerRequestInterface $request): bool {
            $this->assertInstanceOf(
                ServerRequest::class,
                $request
            );

            return true;
        });

        $middleware = $this->container->build(CsrfProtectionMiddleware::class);

        $queue = new MiddlewareQueue();
        $queue->add($middleware);

        $handler = $this->container->build(RequestHandler::class, ['queue' => $queue]);
        $request = $this->container->build(ServerRequest::class, [
            'options' => [
                'method' => 'POST',
            ],
        ]);

        $response = $handler->handle($request);

        $this->assertInstanceOf(
            ClientResponse::class,
            $response
        );
    }

    #[Override]
    protected function setUp(): void
    {
        $this->container = new Container();
        $this->container->singleton(Config::class);
        $this->container->singleton(CsrfProtection::class);

        $this->container->use(Config::class)->set('Csrf.salt', 'l2wyQow3eTwQeTWcfZnlgU8FnbiWljpGjQvNP2pL');
    }
}
