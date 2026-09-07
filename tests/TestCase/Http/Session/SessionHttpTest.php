<?php
declare(strict_types=1);

namespace Tests\TestCase\Http\Session;

use CurlHandle;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Tests\Mock\Http\TestServer;

use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt;
use function curl_setopt_array;
use function glob;
use function http_build_query;
use function is_dir;
use function json_decode;
use function rmdir;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const CURLINFO_COOKIELIST;
use const CURLINFO_RESPONSE_CODE;
use const CURLOPT_COOKIEFILE;
use const CURLOPT_COOKIELIST;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_TIMEOUT;
use const CURLOPT_URL;
use const JSON_THROW_ON_ERROR;

#[RequiresPhpExtension('curl')]
final class SessionHttpTest extends TestCase
{
    protected static string $path;

    protected static TestServer|null $server = null;

    protected static string $url;

    protected CurlHandle $curl;

    /**
     * @return array<string, array{string}>
     */
    public static function readOnlyWriteProvider(): array
    {
        return [
            'clear' => ['clear'],
            'delete' => ['delete'],
            'set' => ['set'],
        ];
    }

    public function testClose(): void
    {
        curl_setopt($this->curl, CURLOPT_URL, self::$url.'?action=close');

        $this->assertJsonStringEqualsJsonString(
            '{"active":false,"started":false}',
            (string) curl_exec($this->curl)
        );
    }

    public function testClosePersistsData(): void
    {
        curl_setopt($this->curl, CURLOPT_URL, self::$url.'?action=close');
        curl_exec($this->curl);
        curl_setopt($this->curl, CURLOPT_URL, self::$url.'?action=read');

        $this->assertJsonStringEqualsJsonString(
            '"value"',
            (string) curl_exec($this->curl)
        );
    }

    public function testExpiredSessionClearsData(): void
    {
        curl_setopt($this->curl, CURLOPT_URL, self::$url.'?action=expire');

        $this->assertJsonStringEqualsJsonString(
            '"value"',
            (string) curl_exec($this->curl)
        );

        curl_setopt($this->curl, CURLOPT_URL, self::$url.'?action=read');

        $this->assertJsonStringEqualsJsonString(
            'null',
            (string) curl_exec($this->curl)
        );
    }

    public function testExpiredSessionRestarts(): void
    {
        curl_setopt($this->curl, CURLOPT_URL, self::$url.'?action=expire');
        curl_exec($this->curl);
        curl_setopt($this->curl, CURLOPT_URL, self::$url.'?action=start');

        $this->assertJsonStringEqualsJsonString(
            '{"active":true,"started":true}',
            (string) curl_exec($this->curl)
        );
    }

    public function testRefreshChangesId(): void
    {
        curl_setopt($this->curl, CURLOPT_URL, self::$url.'?action=close');
        curl_exec($this->curl);
        $cookies = curl_getinfo($this->curl, CURLINFO_COOKIELIST);
        $this->assertIsArray($cookies);
        $this->assertCount(1, $cookies);

        curl_setopt($this->curl, CURLOPT_URL, self::$url.'?action=refresh');
        $response = curl_exec($this->curl);
        $refreshedCookies = curl_getinfo($this->curl, CURLINFO_COOKIELIST);
        $this->assertIsArray($refreshedCookies);
        $this->assertCount(1, $refreshedCookies);

        $id = json_decode((string) $response, flags: JSON_THROW_ON_ERROR);
        $this->assertIsString($id);

        $this->assertNotSame($cookies, $refreshedCookies);
        $this->assertStringEndsWith("\t".$id, $refreshedCookies[0]);
    }

    public function testRefreshDeletesOldSession(): void
    {
        curl_setopt($this->curl, CURLOPT_URL, self::$url.'?action=close');
        curl_exec($this->curl);
        $cookies = curl_getinfo($this->curl, CURLINFO_COOKIELIST);
        $this->assertIsArray($cookies);
        $this->assertCount(1, $cookies);
        $this->assertTrue($cookies[0] !== '');

        curl_setopt($this->curl, CURLOPT_URL, self::$url.'?action=refresh');
        curl_exec($this->curl);
        curl_setopt($this->curl, CURLOPT_COOKIELIST, 'ALL');
        curl_setopt($this->curl, CURLOPT_COOKIELIST, $cookies[0]);
        curl_setopt($this->curl, CURLOPT_URL, self::$url.'?action=read');

        $this->assertJsonStringEqualsJsonString(
            'null',
            (string) curl_exec($this->curl)
        );
    }

    public function testRefreshPreservesData(): void
    {
        curl_setopt($this->curl, CURLOPT_URL, self::$url.'?action=close');
        curl_exec($this->curl);
        curl_setopt($this->curl, CURLOPT_URL, self::$url.'?action=refresh');
        curl_exec($this->curl);
        curl_setopt($this->curl, CURLOPT_URL, self::$url.'?action=read');

        $this->assertJsonStringEqualsJsonString(
            '"value"',
            (string) curl_exec($this->curl)
        );
    }

    public function testStart(): void
    {
        $this->assertJsonStringEqualsJsonString(
            '{"active":true,"started":true}',
            (string) curl_exec($this->curl)
        );
    }

    public function testStartReadOnlyClosesNativeSession(): void
    {
        curl_setopt($this->curl, CURLOPT_URL, self::$url.'?action=close');
        curl_exec($this->curl);
        curl_setopt($this->curl, CURLOPT_URL, self::$url.'?action=read-only-state');

        $this->assertJsonStringEqualsJsonString(
            '{"active":false,"started":true}',
            (string) curl_exec($this->curl)
        );
    }

    public function testStartReadOnlyReadsData(): void
    {
        curl_setopt($this->curl, CURLOPT_URL, self::$url.'?action=close');
        curl_exec($this->curl);
        curl_setopt($this->curl, CURLOPT_URL, self::$url.'?action=read-only');

        $this->assertJsonStringEqualsJsonString(
            '"value"',
            (string) curl_exec($this->curl)
        );
    }

    #[DataProvider('readOnlyWriteProvider')]
    public function testStartReadOnlyRejectsWrites(string $operation): void
    {
        curl_setopt($this->curl, CURLOPT_URL, self::$url.'?action=close');
        curl_exec($this->curl);

        $query = http_build_query([
            'action' => 'read-only-write',
            'operation' => $operation,
        ]);
        curl_setopt($this->curl, CURLOPT_URL, self::$url.'?'.$query);
        $response = curl_exec($this->curl);

        $this->assertSame(409, curl_getinfo($this->curl, CURLINFO_RESPONSE_CODE));
        $this->assertJsonStringEqualsJsonString(
            '"Cannot write to a read-only session."',
            (string) $response
        );
    }

    public function testStartSetsCookie(): void
    {
        curl_exec($this->curl);
        $cookies = curl_getinfo($this->curl, CURLINFO_COOKIELIST);

        $this->assertIsArray($cookies);
        $this->assertCount(1, $cookies);
        $this->assertMatchesRegularExpression('/\tFyreSession\t[A-Za-z0-9,-]+\z/', $cookies[0]);
    }

    #[Override]
    public static function setUpBeforeClass(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fyre-session-');
        self::assertIsString($path);
        unlink($path);
        self::$path = $path;

        self::$server = new TestServer('tests/Mock/Http/Session/server.php', [
            'session.save_path' => self::$path,
            'session.gc_probability' => 0,
        ]);
        self::$url = self::$server->getUrl();
    }

    #[Override]
    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;

        if (isset(self::$path) && is_dir(self::$path)) {
            foreach (glob(self::$path.'/*') ?: [] as $file) {
                unlink($file);
            }

            rmdir(self::$path);
        }
    }

    #[Override]
    protected function setUp(): void
    {
        $curl = curl_init(self::$url);
        $this->assertInstanceOf(CurlHandle::class, $curl);

        curl_setopt_array($curl, [
            CURLOPT_COOKIEFILE => '',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);

        $this->curl = $curl;
    }

    #[Override]
    protected function tearDown(): void
    {
        unset($this->curl);
    }
}
