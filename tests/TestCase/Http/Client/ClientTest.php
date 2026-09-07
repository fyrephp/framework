<?php
declare(strict_types=1);

namespace Tests\TestCase\Http\Client;

use Fyre\Core\Traits\DebugTrait;
use Fyre\Core\Traits\MacroTrait;
use Fyre\Http\Client;
use Fyre\Http\Client\ClientHandler;
use Fyre\Http\Client\Exceptions\RequestException;
use Fyre\Http\Client\Handlers\MockHandler;
use Fyre\Http\Client\Request;
use Fyre\Http\Client\Response;
use Fyre\Http\Cookie\Cookie;
use Fyre\Http\Stream;
use InvalidArgumentException;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use stdClass;

use function class_uses;
use function fclose;
use function fwrite;
use function stream_socket_pair;

use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;

final class ClientTest extends TestCase
{
    protected Client $client;

    protected MockHandler $handler;

    /**
     * @return array<string, array{array<string, int>, string}>
     */
    public static function invalidOptionsProvider(): array
    {
        return [
            'negative redirect body size' => [
                [
                    'maxRedirectBodySize' => -1,
                ],
                'Client option `maxRedirectBodySize` must not be negative.',
            ],
            'negative redirect limit' => [
                [
                    'maxRedirects' => -1,
                ],
                'Client option `maxRedirects` must not be negative.',
            ],
            'negative timeout' => [
                [
                    'timeout' => -1,
                ],
                'Client option `timeout` must not be negative.',
            ],
        ];
    }

    /**
     * @return array<string, array{string, array<string, string>}>
     */
    public static function invalidRedirectLocationProvider(): array
    {
        return [
            'missing header' => ['https://example.com/redirect-empty', []],
            'unsupported scheme' => [
                'https://example.com/redirect-invalid',
                [
                    'Location' => 'mailto:test@example.com',
                ],
            ],
            'malformed URI' => [
                'https://example.com/redirect-invalid',
                [
                    'Location' => '%',
                ],
            ],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function methodProvider(): array
    {
        return [
            'delete' => ['delete', 'DELETE'],
            'get' => ['get', 'GET'],
            'head' => ['head', 'HEAD'],
            'options' => ['options', 'OPTIONS'],
            'patch' => ['patch', 'PATCH'],
            'post' => ['post', 'POST'],
            'put' => ['put', 'PUT'],
            'trace' => ['trace', 'TRACE'],
        ];
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function redirectLocationProvider(): array
    {
        return [
            'fragment' => ['#section', 'https://example.com/page?token=abc', '/page?token=abc'],
            'empty fragment' => ['#', 'https://example.com/page?token=abc', '/page?token=abc'],
            'query' => ['?next=1', 'https://example.com/page?next=1', '/page?next=1'],
            'query and fragment' => ['?next=1#section', 'https://example.com/page?next=1', '/page?next=1'],
            'empty query' => ['?', 'https://example.com/page?', '/page'],
            'empty query and fragment' => ['?#section', 'https://example.com/page?', '/page'],
            'relative path' => ['next', 'https://example.com/next', '/next'],
            'absolute path' => ['/other', 'https://example.com/other', '/other'],
            'absolute URL' => ['https://other.example.com/page', 'https://other.example.com/page', '/page'],
            'network path' => ['//other.example.com/page', 'https://other.example.com/page', '/page'],
        ];
    }

    /**
     * @return array<string, array{int, string, string, string}>
     */
    public static function redirectMethodProvider(): array
    {
        return [
            '301 POST' => [301, 'POST', 'GET', ''],
            '302 POST' => [302, 'POST', 'GET', ''],
            '303 PUT' => [303, 'PUT', 'GET', ''],
            '307 POST' => [307, 'POST', 'POST', 'value'],
            '308 POST' => [308, 'POST', 'POST', 'value'],
        ];
    }

    public function testAgent(): void
    {
        $agent = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/51.0.2704.103 Safari/537.36';
        $mockResponse = new Response();

        $this->handler->addResponse(
            'GET',
            'https://example.com/agent',
            $mockResponse,
            function(RequestInterface $request) use ($agent): bool {
                $this->assertSame(
                    $agent,
                    $request->getHeaderLine('User-Agent')
                );

                return true;
            }
        );

        $this->client->get('https://example.com/agent', options: [
            'headers' => [
                'User-Agent' => $agent,
            ],
        ]);
    }

    public function testBaseUrl(): void
    {
        $mockResponse = new Response();

        $this->handler->addResponse(
            'GET',
            'https://example.com/get?value=1',
            $mockResponse
        );

        $client = new Client([
            'handler' => $this->handler,
            'baseUrl' => 'https://example.com/',
        ]);

        $this->assertSame(
            $mockResponse,
            $client->get('get', [
                'value' => 1,
            ])
        );
    }

    public function testCookies(): void
    {
        $mockResponse = new Response();

        $this->handler->addResponse(
            'GET',
            'https://example.com/cookie',
            $mockResponse,
            function(RequestInterface $request): bool {
                $this->assertSame(
                    'test=value',
                    $request->getHeaderLine('Cookie')
                );

                return true;
            }
        );

        $this->client->addCookie(new Cookie('test', 'value'));
        $this->client->addCookie(new Cookie('test', 'value', [
            'path' => '/other',
        ]));

        $this->client->get('https://example.com/cookie');
    }

    public function testCookiesPersist(): void
    {
        $cookieResponse = new Response([
            'headers' => [
                'Set-Cookie' => 'test=value; Path=/',
            ],
        ]);

        $this->handler->addResponse(
            'GET',
            'https://example.com/set-cookie',
            $cookieResponse
        );

        $mockResponse = new Response();

        $this->handler->addResponse(
            'GET',
            'https://example.com/cookie',
            $mockResponse,
            function(RequestInterface $request): bool {
                $this->assertSame(
                    'test=value',
                    $request->getHeaderLine('Cookie')
                );

                return true;
            }
        );

        $this->client->get('https://example.com/set-cookie');
        $this->client->get('https://example.com/cookie');
    }

    public function testDebug(): void
    {
        $this->assertContains(
            DebugTrait::class,
            class_uses(Client::class)
        );
    }

    public function testGetHandler(): void
    {
        $this->assertSame(
            $this->handler,
            $this->client->getHandler()
        );
    }

    public function testGetQueryString(): void
    {
        $mockResponse = new Response();

        $this->handler->addResponse(
            'GET',
            'https://example.com/get?value=1',
            $mockResponse
        );

        $this->assertSame(
            $mockResponse,
            $this->client->get('https://example.com/get', 'value=1')
        );
    }

    public function testHeader(): void
    {
        $mockResponse = new Response();

        $this->handler->addResponse(
            'GET',
            'https://example.com/header',
            $mockResponse,
            function(RequestInterface $request): bool {
                $this->assertSame(
                    'text/html',
                    $request->getHeaderLine('Accept')
                );

                return true;
            }
        );

        $this->client->get('https://example.com/header', options: [
            'headers' => [
                'Accept' => 'text/html',
            ],
        ]);
    }

    public function testInvalidHandler(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Client handler `stdClass` must extend `Fyre\Http\Client\ClientHandler`.');

        new Client([
            'handler' => new stdClass(),
        ]);
    }

    /**
     * @param array<string, int> $options
     */
    #[DataProvider('invalidOptionsProvider')]
    public function testInvalidOptions(array $options, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs($message);

        new Client($options);
    }

    /**
     * @param array<string, int> $options
     */
    #[DataProvider('invalidOptionsProvider')]
    public function testInvalidRequestOptions(array $options, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs($message);

        $request = new Request('https://example.com');

        $this->client->send($request, $options);
    }

    public function testJsonData(): void
    {
        $mockResponse = new Response();

        $this->handler->addResponse(
            'POST',
            'https://example.com/json',
            $mockResponse,
            function(RequestInterface $request): bool {
                $this->assertSame(
                    'application/json',
                    $request->getHeaderLine('Content-Type')
                );

                $this->assertSame(
                    '{"value":1}',
                    (string) $request->getBody()
                );

                return true;
            }
        );

        $this->client->post('https://example.com/json', [
            'value' => 1,
        ], [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    public function testMacro(): void
    {
        $this->assertContains(
            MacroTrait::class,
            class_uses(Client::class)
        );
    }

    #[DataProvider('methodProvider')]
    public function testMethod(string $method, string $expected): void
    {
        $mockResponse = new Response();

        $this->handler->addResponse(
            $expected,
            'https://example.com/method',
            $mockResponse
        );

        $this->assertSame(
            $mockResponse,
            $this->client->$method('https://example.com/method')
        );
    }

    public function testPatchData(): void
    {
        $mockResponse = new Response();

        $this->handler->addResponse(
            'PATCH',
            'https://example.com/json',
            $mockResponse,
            function(RequestInterface $request): bool {
                $this->assertSame(
                    'application/json',
                    $request->getHeaderLine('Content-Type')
                );

                $this->assertSame(
                    '{"value":1}',
                    (string) $request->getBody()
                );

                return true;
            }
        );

        $this->client->patch('https://example.com/json', [
            'value' => 1,
        ], [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    public function testPostData(): void
    {
        $mockResponse = new Response();

        $this->handler->addResponse(
            'POST',
            'https://example.com/post',
            $mockResponse,
            function(RequestInterface $request): bool {
                $this->assertSame(
                    'application/x-www-form-urlencoded',
                    $request->getHeaderLine('Content-Type')
                );

                $this->assertSame(
                    'value=1',
                    (string) $request->getBody()
                );

                return true;
            }
        );

        $this->client->post('https://example.com/post', [
            'value' => 1,
        ]);
    }

    public function testPostRawData(): void
    {
        $mockResponse = new Response();

        $this->handler->addResponse(
            'POST',
            'https://example.com/post',
            $mockResponse,
            function(RequestInterface $request): bool {
                $this->assertSame(
                    'This is a test.',
                    (string) $request->getBody()
                );

                return true;
            }
        );

        $this->assertSame(
            $mockResponse,
            $this->client->post('https://example.com/post', 'This is a test.')
        );
    }

    public function testPutData(): void
    {
        $mockResponse = new Response();

        $this->handler->addResponse(
            'PUT',
            'https://example.com/json',
            $mockResponse,
            function(RequestInterface $request): bool {
                $this->assertSame(
                    'application/json',
                    $request->getHeaderLine('Content-Type')
                );

                $this->assertSame(
                    '{"value":1}',
                    (string) $request->getBody()
                );

                return true;
            }
        );

        $this->client->put('https://example.com/json', [
            'value' => 1,
        ], [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    public function testRedirect(): void
    {
        $redirectResponse = new Response([
            'statusCode' => 302,
            'headers' => [
                'Location' => '/get?value=1',
            ],
        ]);

        $this->handler->addResponse(
            'GET',
            'https://example.com/redirect',
            $redirectResponse
        );

        $mockResponse = new Response();

        $this->handler->addResponse(
            'GET',
            'https://example.com/get?value=1',
            $mockResponse
        );

        $response = $this->client->get('https://example.com/redirect', options: [
            'maxRedirects' => 1,
        ]);

        $this->assertSame($mockResponse, $response);
    }

    #[DataProvider('redirectMethodProvider')]
    public function testRedirectBody(int $statusCode, string $method, string $expectedMethod, string $expectedBody): void
    {
        $redirectResponse = new Response([
            'statusCode' => $statusCode,
            'headers' => [
                'Location' => '/redirect-target?value=1',
            ],
        ]);

        $this->handler->addResponse(
            $method,
            'https://example.com/redirect-method?status='.$statusCode,
            $redirectResponse
        );

        $mockResponse = new Response();

        $this->handler->addResponse(
            $expectedMethod,
            'https://example.com/redirect-target?value=1',
            $mockResponse,
            function(RequestInterface $request) use ($expectedBody): bool {
                $this->assertSame($expectedBody, (string) $request->getBody());

                return true;
            }
        );

        $request = new Request(
            'https://example.com/redirect-method?status='.$statusCode,
            [
                'method' => $method,
                'body' => 'value',
                'headers' => [
                    'Content-Length' => '5',
                    'Content-Type' => 'text/plain',
                ],
            ]
        );

        $response = $this->client->send($request, [
            'maxRedirects' => 1,
        ]);

        $this->assertSame($mockResponse, $response);
    }

    public function testRedirectBodyEmptyRead(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessageIs('Request body cannot be buffered for redirect replay.');

        $body = $this->createStub(StreamInterface::class);
        $body->method('isSeekable')
            ->willReturn(false);
        $body->method('read')
            ->willReturn('');
        $body->method('eof')
            ->willReturn(false);

        $handler = $this->createMock(ClientHandler::class);
        $handler->expects($this->never())
            ->method('send');

        $client = new Client([
            'handler' => $handler,
        ]);
        $request = new Request(
            'https://example.com/redirect',
            [
                'method' => 'POST',
                'body' => $body,
            ]
        );

        $client->send($request, [
            'maxRedirects' => 1,
        ]);
    }

    public function testRedirectBodyRewindException(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessageIs('Request body cannot be replayed after redirect.');

        $body = $this->createStub(StreamInterface::class);
        $body->method('isSeekable')
            ->willReturn(true);
        $body->method('getSize')
            ->willReturn(5);
        $body->method('rewind')
            ->willThrowException(new RuntimeException('Cannot rewind.'));

        $redirectResponse = new Response([
            'statusCode' => 307,
            'headers' => [
                'Location' => '/redirect-target',
            ],
        ]);

        $handler = $this->createMock(ClientHandler::class);
        $handler->expects($this->once())
            ->method('send')
            ->willReturn($redirectResponse);

        $client = new Client([
            'handler' => $handler,
        ]);
        $request = new Request(
            'https://example.com/redirect',
            [
                'method' => 'POST',
                'body' => $body,
            ]
        );

        $client->send($request, [
            'maxRedirects' => 1,
        ]);
    }

    public function testRedirectBodySizeLimit(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessageIs('Request body cannot be buffered for redirect replay.');

        $sockets = stream_socket_pair(
            STREAM_PF_UNIX,
            STREAM_SOCK_STREAM,
            STREAM_IPPROTO_IP
        );

        $this->assertNotFalse($sockets);

        [$reader, $writer] = $sockets;

        fwrite($writer, 'value');
        fclose($writer);

        $body = new Stream($reader);
        $request = new Request(
            'https://example.com/redirect',
            [
                'method' => 'POST',
                'body' => $body,
            ]
        );

        $this->client->send($request, [
            'maxRedirects' => 1,
            'maxRedirectBodySize' => 4,
        ]);
    }

    #[DataProvider('redirectMethodProvider')]
    public function testRedirectContentType(int $statusCode, string $method, string $expectedMethod, string $expectedBody): void
    {
        $redirectResponse = new Response([
            'statusCode' => $statusCode,
            'headers' => [
                'Location' => '/redirect-target?value=1',
            ],
        ]);

        $this->handler->addResponse(
            $method,
            'https://example.com/redirect-method?status='.$statusCode,
            $redirectResponse
        );

        $mockResponse = new Response();

        $this->handler->addResponse(
            $expectedMethod,
            'https://example.com/redirect-target?value=1',
            $mockResponse,
            function(RequestInterface $request) use ($expectedBody): bool {
                $this->assertSame(
                    $expectedBody === '' ? '' : 'text/plain',
                    $request->getHeaderLine('Content-Type')
                );

                return true;
            }
        );

        $request = new Request(
            'https://example.com/redirect-method?status='.$statusCode,
            [
                'method' => $method,
                'body' => 'value',
                'headers' => [
                    'Content-Length' => '5',
                    'Content-Type' => 'text/plain',
                ],
            ]
        );

        $response = $this->client->send($request, [
            'maxRedirects' => 1,
        ]);

        $this->assertSame($mockResponse, $response);
    }

    public function testRedirectFragmentLoop(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessageIs('Redirect loop detected.');

        $redirectResponse = new Response([
            'statusCode' => 302,
            'headers' => [
                'Location' => '#section',
            ],
        ]);

        $this->handler->addResponse(
            'GET',
            'https://example.com/page?token=abc',
            $redirectResponse
        );

        $this->client->get('https://example.com/page?token=abc', options: [
            'maxRedirects' => 1,
        ]);
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('invalidRedirectLocationProvider')]
    public function testRedirectInvalidLocation(string $url, array $headers): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessageIs('Redirect location is not valid.');

        $mockResponse = new Response([
            'statusCode' => 302,
            'headers' => $headers,
        ]);

        $this->handler->addResponse(
            'GET',
            $url,
            $mockResponse
        );

        $this->client->get($url, options: [
            'maxRedirects' => 1,
        ]);
    }

    #[DataProvider('redirectLocationProvider')]
    public function testRedirectLocation(string $location, string $expectedUrl, string $expectedTarget): void
    {
        $redirectResponse = new Response([
            'statusCode' => 303,
            'headers' => [
                'Location' => $location,
            ],
        ]);

        $this->handler->addResponse(
            'POST',
            'https://example.com/page?token=abc',
            $redirectResponse
        );

        $mockResponse = new Response();

        $this->handler->addResponse(
            'GET',
            $expectedUrl,
            $mockResponse,
            function(RequestInterface $request) use ($expectedTarget): bool {
                $this->assertSame(
                    $expectedTarget,
                    $request->getRequestTarget()
                );

                return true;
            }
        );

        $response = $this->client->post('https://example.com/page?token=abc', options: [
            'maxRedirects' => 1,
        ]);

        $this->assertSame(
            $mockResponse,
            $response
        );
    }

    public function testRedirectLoop(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessageIs('Redirect loop detected.');

        $response1 = new Response([
            'statusCode' => 302,
            'headers' => [
                'Location' => '/redirect-loop-b',
            ],
        ]);

        $this->handler->addResponse(
            'GET',
            'https://example.com/redirect-loop-a',
            $response1
        );

        $response2 = new Response([
            'statusCode' => 302,
            'headers' => [
                'Location' => '/redirect-loop-a',
            ],
        ]);

        $this->handler->addResponse(
            'GET',
            'https://example.com/redirect-loop-b',
            $response2
        );

        $this->client->get('https://example.com/redirect-loop-a', options: [
            'maxRedirects' => 3,
        ]);
    }

    public function testRedirectNonSeekableBody(): void
    {
        $redirectResponse = new Response([
            'statusCode' => 307,
            'headers' => [
                'Location' => '/redirect-target',
            ],
        ]);

        $this->handler->addResponse(
            'POST',
            'https://example.com/redirect-method',
            $redirectResponse
        );

        $mockResponse = new Response();

        $this->handler->addResponse(
            'POST',
            'https://example.com/redirect-target',
            $mockResponse,
            function(RequestInterface $request): bool {
                $this->assertSame(
                    'value',
                    (string) $request->getBody()
                );

                return true;
            }
        );

        $sockets = stream_socket_pair(
            STREAM_PF_UNIX,
            STREAM_SOCK_STREAM,
            STREAM_IPPROTO_IP
        );

        $this->assertNotFalse($sockets);

        [$reader, $writer] = $sockets;

        fwrite($writer, 'value');
        fclose($writer);

        $body = new Stream($reader);
        $request = new Request(
            'https://example.com/redirect-method',
            [
                'method' => 'POST',
                'body' => $body,
            ]
        );

        $response = $this->client->send($request, [
            'maxRedirects' => 1,
        ]);

        $body->close();

        $this->assertSame($mockResponse, $response);
    }

    public function testRedirectRebuildsCookies(): void
    {
        $redirectResponse = new Response([
            'statusCode' => 302,
            'headers' => [
                'Location' => 'next',
            ],
        ]);

        $this->handler->addResponse(
            'GET',
            'https://example.com/redirect-private/start',
            $redirectResponse,
            function(RequestInterface $request): bool {
                $this->assertSame(
                    'private=value',
                    $request->getHeaderLine('Cookie')
                );

                return true;
            }
        );

        $mockResponse = new Response();

        $this->handler->addResponse(
            'GET',
            'https://example.com/redirect-private/next',
            $mockResponse,
            function(RequestInterface $request): bool {
                $this->assertSame(
                    '',
                    $request->getHeaderLine('Cookie')
                );

                return true;
            }
        );

        $this->client->addCookie(new Cookie('private', 'value', [
            'domain' => 'example.com',
            'path' => '/redirect-private/start',
        ]));

        $response = $this->client->get('https://example.com/redirect-private/start', options: [
            'maxRedirects' => 1,
        ]);

        $this->assertSame($mockResponse, $response);
    }

    public function testRedirectStripsCrossOriginCredentials(): void
    {
        $redirectResponse = new Response([
            'statusCode' => 302,
            'headers' => [
                'Location' => 'https://other.example.com/redirect-target',
            ],
        ]);

        $this->handler->addResponse(
            'GET',
            'https://source.example.com/redirect-cross-origin',
            $redirectResponse,
            function(RequestInterface $request): bool {
                $this->assertSame(
                    'source=value',
                    $request->getHeaderLine('Cookie')
                );

                return true;
            }
        );

        $mockResponse = new Response();

        $this->handler->addResponse(
            'GET',
            'https://other.example.com/redirect-target',
            $mockResponse,
            function(RequestInterface $request): bool {
                $this->assertSame(
                    '',
                    $request->getHeaderLine('Authorization')
                );

                $this->assertSame(
                    '',
                    $request->getHeaderLine('Proxy-Authorization')
                );

                $this->assertSame(
                    '',
                    $request->getHeaderLine('Referer')
                );

                $this->assertSame(
                    '',
                    $request->getHeaderLine('X-Api-Key')
                );

                $this->assertSame(
                    'target=value',
                    $request->getHeaderLine('Cookie')
                );

                return true;
            }
        );

        $this->client->addCookie(new Cookie('source', 'value', [
            'domain' => 'source.example.com',
        ]));
        $this->client->addCookie(new Cookie('target', 'value', [
            'domain' => 'other.example.com',
        ]));

        $response = $this->client->get('https://source.example.com/redirect-cross-origin', options: [
            'maxRedirects' => 1,
            'sensitiveHeaders' => [
                'X-Api-Key',
                'Authorization',
            ],
            'headers' => [
                'Authorization' => 'Bearer secret',
                'Proxy-Authorization' => 'Basic secret',
                'Referer' => 'https://source.example.com/private',
                'X-Api-Key' => 'secret',
            ],
        ]);

        $this->assertSame($mockResponse, $response);
    }

    public function testSendConfiguredOptions(): void
    {
        $redirectResponse = new Response([
            'statusCode' => 302,
            'headers' => [
                'Location' => '/get?value=1',
            ],
        ]);

        $this->handler->addResponse(
            'GET',
            'https://example.com/redirect',
            $redirectResponse
        );

        $mockResponse = new Response();

        $this->handler->addResponse(
            'GET',
            'https://example.com/get?value=1',
            $mockResponse
        );

        $client = new Client([
            'handler' => $this->handler,
            'maxRedirects' => 1,
        ]);
        $request = new Request('https://example.com/redirect');

        $this->assertSame(
            $mockResponse,
            $client->send($request)
        );
    }

    public function testSendCookies(): void
    {
        $mockResponse = new Response();

        $this->handler->addResponse(
            'GET',
            'https://example.com/cookie',
            $mockResponse,
            function(RequestInterface $request): bool {
                $this->assertSame(
                    'test=value',
                    $request->getHeaderLine('Cookie')
                );

                return true;
            }
        );

        $this->client->addCookie(new Cookie('test', 'value', [
            'domain' => 'example.com',
            'hostOnly' => true,
        ]));

        $request = new Request('https://example.com/cookie');

        $this->client->send($request);
    }

    #[Override]
    protected function setUp(): void
    {
        $this->handler = new MockHandler();
        $this->client = new Client([
            'handler' => $this->handler,
        ]);
    }
}
