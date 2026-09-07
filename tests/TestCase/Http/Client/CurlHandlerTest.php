<?php
declare(strict_types=1);

namespace Tests\TestCase\Http\Client;

use Fyre\Http\Client;
use Fyre\Http\Client\Exceptions\NetworkException;
use Fyre\Http\Client\Exceptions\RequestException;
use Fyre\Http\Client\Handlers\CurlHandler;
use Fyre\Http\Client\Request;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Tests\Mock\Http\TestServer;

use function fopen;
use function http_build_query;

#[RequiresPhpExtension('curl')]
final class CurlHandlerTest extends TestCase
{
    protected static TestServer|null $server = null;

    protected static string $url;

    /**
     * @return array<string, array{string, int, string, string, string, string}>
     */
    public static function gzipResponseProvider(): array
    {
        return [
            'body' => ['GET', 200, 'test', 'test', '', ''],
            'empty body' => ['GET', 200, '', '', '', ''],
            'head' => ['HEAD', 200, 'test', '', 'gzip', '24'],
            'not modified' => ['GET', 304, 'test', '', 'gzip', '24'],
            'no content' => ['GET', 204, 'test', '', 'gzip', ''],
        ];
    }

    public function testAuthBasic(): void
    {
        $response = new Client([
            'auth' => [
                'username' => 'test',
                'password' => 'password',
            ],
        ])->get(self::$url.'/auth');

        $this->assertTrue(
            $response->isOk()
        );

        $this->assertTrue(
            $response->isSuccess()
        );
    }

    public function testAuthDigest(): void
    {
        $response = new Client([
            'auth' => [
                'type' => 'digest',
                'username' => 'test',
                'password' => 'password',
            ],
        ])->get(self::$url.'/auth-digest');

        $this->assertTrue(
            $response->isOk()
        );

        $this->assertTrue(
            $response->isSuccess()
        );
    }

    public function testGetData(): void
    {
        $response = new Client()->get(self::$url.'/get', [
            'value' => 1,
        ]);

        $this->assertTrue(
            $response->isOk()
        );

        $this->assertTrue(
            $response->isSuccess()
        );

        $this->assertArraysAreIdentical(
            [
                'value' => '1',
            ],
            $response->getJson()
        );
    }

    #[DataProvider('gzipResponseProvider')]
    #[RequiresPhpExtension('zlib')]
    public function testGzipResponse(string $method, int $statusCode, string $body, string $expectedBody, string $expectedEncoding, string $expectedLength): void
    {
        $query = http_build_query([
            'status' => $statusCode,
            'body' => $body,
        ]);

        $response = new CurlHandler()->send(new Request(self::$url.'/gzip?'.$query, [
            'method' => $method,
        ]));

        $this->assertSame(
            $statusCode,
            $response->getStatusCode()
        );

        $this->assertSame(
            $expectedBody,
            $response->getBody()->getContents()
        );

        $this->assertSame(
            $expectedEncoding,
            $response->getHeaderLine('Content-Encoding')
        );

        $this->assertSame(
            $expectedLength,
            $response->getHeaderLine('Content-Length')
        );
    }

    public function testProtocolVersion(): void
    {
        $response = new Client()->get(self::$url.'/version', options: [
            'protocolVersion' => '1.0',
        ]);

        $this->assertTrue(
            $response->isOk()
        );

        $this->assertTrue(
            $response->isSuccess()
        );

        $this->assertSame(
            'HTTP/1.0',
            $response->getBody()->getContents()
        );
    }

    public function testProxy(): void
    {
        $response = new Client([
            'proxy' => [
                'username' => 'test',
                'password' => 'password',
            ],
        ])->get(self::$url.'/proxy');

        $this->assertTrue(
            $response->isOk()
        );

        $this->assertTrue(
            $response->isSuccess()
        );
    }

    public function testSendNetworkException(): void
    {
        $this->expectException(NetworkException::class);

        new Client()->get('http://127.0.0.1:1', options: [
            'timeout' => 1,
        ]);
    }

    public function testSendRequestException(): void
    {
        $this->expectException(RequestException::class);

        new Client()->get('foo://example.com', options: [
            'timeout' => 1,
        ]);
    }

    public function testUncompressedResponse(): void
    {
        $response = new Client()->get(self::$url.'/plain');

        $this->assertSame(
            'test',
            $response->getBody()->getContents()
        );

        $this->assertFalse(
            $response->hasHeader('Content-Encoding')
        );

        $this->assertSame(
            '4',
            $response->getHeaderLine('Content-Length')
        );
    }

    public function testUpload(): void
    {
        $file = fopen('tests/assets/test.txt', 'r');

        $response = new Client()->post(self::$url.'/upload', [
            'deep' => [
                'value' => $file,
            ],
        ]);

        $this->assertTrue(
            $response->isOk()
        );

        $this->assertTrue(
            $response->isSuccess()
        );

        $data = $response->getJson();

        unset($data['deep']['tmp_name']);

        $this->assertArraysAreIdentical(
            [
                'deep' => [
                    'name' => [
                        'value' => 'test.txt',
                    ],
                    'full_path' => [
                        'value' => 'test.txt',
                    ],
                    'type' => [
                        'value' => 'text/plain',
                    ],
                    'error' => [
                        'value' => 0,
                    ],
                    'size' => [
                        'value' => 15,
                    ],
                ],
            ],
            $data
        );
    }

    #[Override]
    public static function setUpBeforeClass(): void
    {
        self::$server = new TestServer('tests/Mock/Http/Client/server.php');
        self::$url = self::$server->getUrl();
    }

    #[Override]
    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }
}
