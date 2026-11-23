<?php
declare(strict_types=1);

namespace Tests\TestCase\Http\Client;

use Closure;
use Fyre\Http\Client\Handlers\CurlHandler;
use Fyre\Http\Client\Request;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

use function defined;

use const CURL_HTTP_VERSION_2_0;
use const CURLOPT_CUSTOMREQUEST;
use const CURLOPT_HEADER;
use const CURLOPT_HTTP_VERSION;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_PROTOCOLS;
use const CURLOPT_PROTOCOLS_STR;
use const CURLOPT_PROXYHEADER;
use const CURLOPT_PROXYPASSWORD;
use const CURLOPT_PROXYUSERNAME;
use const CURLOPT_SSL_VERIFYPEER;
use const CURLOPT_SSLCERT;
use const CURLOPT_SSLCERTPASSWD;
use const CURLOPT_SSLKEY;
use const CURLOPT_TIMEOUT;
use const CURLOPT_URL;
use const CURLPROTO_HTTP;
use const CURLPROTO_HTTPS;

#[RequiresPhpExtension('curl')]
final class CurlHandlerOptionsTest extends TestCase
{
    /**
     * @var mixed[]
     */
    protected array $options;

    /**
     * @return array<string, array{int, string}>
     */
    public static function sslOptionProvider(): array
    {
        return [
            'certificate' => [CURLOPT_SSLCERT, '/path/to/cert'],
            'password' => [CURLOPT_SSLCERTPASSWD, 'secret'],
            'key' => [CURLOPT_SSLKEY, '/path/to/key'],
        ];
    }

    public function testAllowedProtocols(): void
    {
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $this->assertSame('http,https', $this->options[CURLOPT_PROTOCOLS_STR]);
        } else {
            $this->assertSame(CURLPROTO_HTTP | CURLPROTO_HTTPS, $this->options[CURLOPT_PROTOCOLS]);
        }
    }

    public function testBody(): void
    {
        $this->assertSame('value', $this->options[CURLOPT_POSTFIELDS]);
    }

    public function testGetBodyPreservesMethod(): void
    {
        $this->assertSame('GET', $this->options[CURLOPT_CUSTOMREQUEST]);
    }

    public function testHeaders(): void
    {
        $this->assertArraysAreIdentical(
            ['Test: header', 'Host: example.com'],
            $this->options[CURLOPT_HTTPHEADER]
        );
    }

    public function testIncludesResponseHeaders(): void
    {
        $this->assertTrue($this->options[CURLOPT_HEADER]);
    }

    public function testProtocolVersion(): void
    {
        $this->assertSame(CURL_HTTP_VERSION_2_0, $this->options[CURLOPT_HTTP_VERSION]);
    }

    public function testProxyCredentials(): void
    {
        $this->assertSame('test', $this->options[CURLOPT_PROXYUSERNAME]);
        $this->assertSame('password', $this->options[CURLOPT_PROXYPASSWORD]);
        $this->assertArrayNotHasKey(CURLOPT_PROXYHEADER, $this->options);
    }

    public function testProxyHeader(): void
    {
        $request = new Request('https://example.com/test')->withHeader('proxy-authorization', 'Bearer test');
        $options = Closure::bind(
            static fn(): array => CurlHandler::buildOptions($request),
            null,
            CurlHandler::class
        )();

        $this->assertSame(['Proxy-Authorization: Bearer test'], $options[CURLOPT_PROXYHEADER]);
        $this->assertSame(['Host: example.com'], $options[CURLOPT_HTTPHEADER]);
    }

    #[DataProvider('sslOptionProvider')]
    public function testSslOption(int $option, string $expected): void
    {
        $this->assertSame($expected, $this->options[$option]);
    }

    public function testTimeout(): void
    {
        $this->assertSame(5, $this->options[CURLOPT_TIMEOUT]);
    }

    public function testUrl(): void
    {
        $this->assertSame('https://example.com/test', $this->options[CURLOPT_URL]);
    }

    public function testVerifyPeerDisabled(): void
    {
        $this->assertFalse($this->options[CURLOPT_SSL_VERIFYPEER]);
    }

    #[Override]
    protected function setUp(): void
    {
        $request = new Request('https://example.com/test', [
            'method' => 'get',
            'body' => 'value',
            'headers' => [
                'Test' => 'header',
                'Proxy-Authorization' => 'Bearer test',
            ],
            'protocolVersion' => '2.0',
        ]);

        $this->options = Closure::bind(
            static fn(): array => CurlHandler::buildOptions($request, [
                'proxy' => [
                    'username' => 'test',
                    'password' => 'password',
                ],
                'timeout' => 5,
                'ssl' => [
                    'cert' => '/path/to/cert',
                    'password' => 'secret',
                    'key' => '/path/to/key',
                ],
                'verifyPeer' => false,
            ]),
            null,
            CurlHandler::class
        )();
    }
}
