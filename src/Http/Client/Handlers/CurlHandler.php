<?php
declare(strict_types=1);

namespace Fyre\Http\Client\Handlers;

use Fyre\Http\Client\ClientHandler;
use Fyre\Http\Client\Exceptions\NetworkException;
use Fyre\Http\Client\Exceptions\RequestException;
use Fyre\Http\Client\Response;
use Fyre\Http\Stream;
use Override;
use Psr\Http\Message\RequestInterface;

use function array_keys;
use function array_map;
use function curl_errno;
use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt_array;
use function defined;
use function explode;
use function in_array;
use function preg_match;
use function sprintf;
use function str_contains;
use function strpos;
use function substr;
use function trim;

use const CURL_HTTP_VERSION_1_0;
use const CURL_HTTP_VERSION_1_1;
use const CURL_HTTP_VERSION_2_0;
use const CURL_HTTP_VERSION_NONE;
use const CURLE_COULDNT_CONNECT;
use const CURLE_COULDNT_RESOLVE_HOST;
use const CURLE_GOT_NOTHING;
use const CURLE_OPERATION_TIMEDOUT;
use const CURLE_RECV_ERROR;
use const CURLE_SEND_ERROR;
use const CURLE_SSL_CONNECT_ERROR;
use const CURLINFO_HEADER_SIZE;
use const CURLOPT_CUSTOMREQUEST;
use const CURLOPT_ENCODING;
use const CURLOPT_HEADER;
use const CURLOPT_HTTP_VERSION;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_NOBODY;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_PROTOCOLS;
use const CURLOPT_PROTOCOLS_STR;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_SSL_VERIFYPEER;
use const CURLOPT_SSLCERT;
use const CURLOPT_SSLCERTPASSWD;
use const CURLOPT_SSLKEY;
use const CURLOPT_TIMEOUT;
use const CURLOPT_URL;
use const CURLPROTO_HTTP;
use const CURLPROTO_HTTPS;

/**
 * Provides a {@see ClientHandler} implementation using PHP's cURL extension. Builds a
 * response by parsing the raw header/body output from cURL.
 */
class CurlHandler extends ClientHandler
{
    /**
     * {@inheritDoc}
     *
     * @throws NetworkException If a network error occurs.
     * @throws RequestException If a request error occurs.
     */
    #[Override]
    public function send(RequestInterface $request, array $options = []): Response
    {
        $options = static::buildOptions($request, $options);

        $handle = curl_init();

        if (!curl_setopt_array($handle, $options)) {
            throw new RequestException('Failed to set cURL request options.', $request);
        }

        $output = curl_exec($handle);

        if ($output === false) {
            $errorCode = curl_errno($handle);
            $error = curl_error($handle);

            $message = sprintf(
                'cURL error (%d): %s',
                $errorCode,
                $error
            );

            if (in_array($errorCode, [
                CURLE_COULDNT_RESOLVE_HOST,
                CURLE_COULDNT_CONNECT,
                CURLE_OPERATION_TIMEDOUT,
                CURLE_RECV_ERROR,
                CURLE_SEND_ERROR,
                CURLE_GOT_NOTHING,
                CURLE_SSL_CONNECT_ERROR,
            ], true)) {
                throw new NetworkException($message, $request, $errorCode);
            }

            throw new RequestException($message, $request, $errorCode);
        }

        $headerSize = curl_getinfo($handle, CURLINFO_HEADER_SIZE);

        $response = static::buildResponse((string) $output, $headerSize);

        if (
            $request->getMethod() !== 'HEAD' &&
            $response->getStatusCode() >= 200 &&
            !in_array($response->getStatusCode(), [204, 304], true) &&
            $response->hasHeader('Content-Encoding')
        ) {
            $response = $response
                ->withoutHeader('Content-Encoding')
                ->withoutHeader('Content-Length');
        }

        return $response;
    }

    /**
     * Builds cURL options.
     *
     * Note: The request body is sent via `CURLOPT_POSTFIELDS` when it is non-empty, even for
     * methods other than POST. When a non-empty body is present on a GET request, the method
     * is forced back to GET using `CURLOPT_CUSTOMREQUEST`.
     *
     * Supported config keys:
     * - `timeout` (int): The request timeout in seconds.
     * - `ssl` (array): SSL options (`cert`, `password`, `key`).
     * - `verifyPeer` (bool): Whether to verify the peer certificate.
     *
     * @param RequestInterface $request The Request.
     * @param array<string, mixed> $config The config.
     * @return mixed[] The cURL options.
     *
     * @throws RequestException If the request URL is not an absolute HTTP or HTTPS URL.
     */
    protected static function buildOptions(RequestInterface $request, array $config = []): array
    {
        $uri = $request->getUri();

        if (!in_array($uri->getScheme(), ['http', 'https'], true) || $uri->getHost() === '') {
            throw new RequestException('Request URL must be an absolute HTTP or HTTPS URL.', $request);
        }

        $options = [
            CURLOPT_URL => (string) $uri,
            CURLOPT_HTTP_VERSION => match ($request->getProtocolVersion()) {
                '1.0' => CURL_HTTP_VERSION_1_0,
                '1.1' => CURL_HTTP_VERSION_1_1,
                '2.0' => CURL_HTTP_VERSION_2_0,
                default => CURL_HTTP_VERSION_NONE,
            },
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => array_map(
                static fn(string $name): string => $name.': '.$request->getHeaderLine($name),
                $request->getHeaders() |> array_keys(...)
            ),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
        ];

        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = 'http,https';
        } else {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }

        $method = $request->getMethod();
        switch ($method) {
            case 'GET':
                $options[CURLOPT_HTTPGET] = true;
                break;
            case 'HEAD':
                $options[CURLOPT_NOBODY] = 1;
                break;
            case 'POST':
                $options[CURLOPT_POST] = true;
                break;
            default:
                $options[CURLOPT_POST] = true;
                $options[CURLOPT_CUSTOMREQUEST] = $method;
                break;
        }

        $options[CURLOPT_POSTFIELDS] = (string) $request->getBody();

        if ($options[CURLOPT_POSTFIELDS] === '') {
            unset($options[CURLOPT_POSTFIELDS]);
        } else if ($method === 'GET') {
            $options[CURLOPT_CUSTOMREQUEST] = 'GET';
        }

        if (isset($config['timeout'])) {
            $options[CURLOPT_TIMEOUT] = $config['timeout'];
        }

        if (isset($config['ssl'])) {
            $options[CURLOPT_SSLCERT] = $config['ssl']['cert'] ?? null;
            $options[CURLOPT_SSLCERTPASSWD] = $config['ssl']['password'] ?? null;
            $options[CURLOPT_SSLKEY] = $config['ssl']['key'] ?? null;
        }

        if (isset($config['verifyPeer'])) {
            $options[CURLOPT_SSL_VERIFYPEER] = $config['verifyPeer'];
        }

        return $options;
    }

    /**
     * Builds the Response.
     *
     * Note: When the output contains multiple status lines (e.g. `100 Continue` followed by
     * the final response), the last parsed status line wins.
     *
     * @param string $contents The response contents.
     * @param int $headerSize The header size.
     * @return Response The new Response instance.
     */
    protected static function buildResponse(string $contents, int $headerSize): Response
    {
        $header = substr($contents, 0, $headerSize) |> trim(...);
        $body = substr($contents, $headerSize);

        $response = new Response();

        $headers = explode("\r\n", $header);

        foreach ($headers as $header) {
            if (strpos($header, 'HTTP') === 0) {
                if (preg_match('/\AHTTP\/([12](?:\.[01])?) (\d+)(?: (.*))?\z/', $header, $matches)) {
                    $protocolVersion = $matches[1] === '2' ? '2.0' : $matches[1];

                    // Discard headers from an informational/proxy response when the next
                    // response block begins.
                    $response = new Response()
                        ->withStatus((int) $matches[2], $matches[3] ?? '')
                        ->withProtocolVersion($protocolVersion);
                }
            } else if (str_contains($header, ':')) {
                [$name, $value] = explode(':', $header, 2);
                $name = trim($name);
                $value = trim($value);

                $response = $response->withAddedHeader($name, $value);
            }
        }

        $stream = Stream::createFromString($body);

        return $response->withBody($stream);
    }
}
