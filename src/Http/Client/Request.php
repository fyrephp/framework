<?php
declare(strict_types=1);

namespace Fyre\Http\Client;

use finfo;
use Fyre\Core\Traits\MacroTrait;
use Fyre\Http\Client\Exceptions\RequestException;
use Fyre\Http\Request as HttpRequest;
use Fyre\Http\Stream;
use Fyre\Utility\Str;
use JsonException;
use Psr\Http\Message\UploadedFileInterface;

use function array_map;
use function base64_encode;
use function basename;
use function bin2hex;
use function explode;
use function hash;
use function http_build_query;
use function implode;
use function in_array;
use function is_array;
use function is_resource;
use function json_encode;
use function md5;
use function preg_match_all;
use function random_bytes;
use function sprintf;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function stream_get_contents;
use function stream_get_meta_data;
use function strtolower;
use function trim;

use const PREG_SET_ORDER;
use const PREG_UNMATCHED_AS_NULL;

/**
 * Extends {@see HttpRequest} with helpers for client-side authentication and request body
 * encoding.
 */
class Request extends HttpRequest
{
    use MacroTrait;

    /**
     * Returns the new Request instance with a Basic Authorization header.
     *
     * @param string $username The username.
     * @param string $password The password.
     * @return static The new Request instance with the Basic Authorization header.
     */
    public function withAuthBasic(string $username, string $password): static
    {
        return $this->withHeader('Authorization', 'Basic '.base64_encode($username.':'.$password));
    }

    /**
     * Returns the new Request instance with a Digest Authorization header.
     *
     * Parses the `WWW-Authenticate` header from a `401` response and constructs an RFC 7616
     * `Authorization: Digest ...` header.
     *
     * Note: Only a subset of algorithms are supported. When `qop` includes both `auth` and
     * `auth-int`, `auth-int` is preferred.
     *
     * @param string $www The `WWW-Authenticate` header value.
     * @param string $username The username.
     * @param string $password The password.
     * @param int $nc The nonce count.
     * @return static The new Request instance with the Digest Authorization header.
     *
     * @throws RequestException If the header is invalid or unsupported options are provided.
     */
    public function withAuthDigest(string $www, string $username, string $password, int $nc = 1): static
    {
        // parse www header
        preg_match_all('/(\w+)=(?:"([^"]+)"|([^\s,$]+))/', $www, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);

        $c = [];
        foreach ($matches as $v) {
            $c[$v[1]] = $v[2] ?: $v[3];
        }

        if (!isset($c['realm'], $c['nonce'])) {
            throw new RequestException('WWW-Authenticate header is not valid.', $this);
        }

        // determine hash algorithm
        $c['algorithm'] ??= 'MD5';

        $algo = strtolower($c['algorithm']);
        $hash = match ($algo) {
            'md5', 'md5-sess' => md5(...),
            'sha-256', 'sha-256-sess' => hash('sha256', ?),
            'sha-512-256', 'sha-512-256-sess' => hash('sha512/256', ?),
            default => throw new RequestException(sprintf(
                'Algorithm `%s` is not supported',
                $algo
            ), $this),
        };

        // determine qop
        $qop = 'auth';
        if (isset($c['qop'])) {
            $options = explode(',', $c['qop']) |> array_map(trim(...), ?);

            if (in_array('auth-int', $options, true)) {
                $qop = 'auth-int';
            } else if (!in_array('auth', $options, true)) {
                throw new RequestException(sprintf(
                    'QOP `%s` is not supported.',
                    $c['qop']
                ), $this);
            }
        }

        // build uri string
        $uri = $this->uri->getPath();
        if ($this->uri->getQuery()) {
            $uri .= '?'.$this->uri->getQuery();
        }

        // nonce
        $ncHex = sprintf('%08x', $nc);
        $cnonce = random_bytes(8) |> bin2hex(...);

        // ha1
        $ha1 = implode(':', [$username, $c['realm'], $password]) |> $hash;

        if (str_ends_with($algo, '-sess')) {
            $ha1 = implode(':', [$ha1, $c['nonce'], $cnonce]) |> $hash;
        }

        // ha2
        $ha2Parts = [$this->getMethod(), $uri];

        if ($qop === 'auth-int') {
            $ha2Parts[] = ((string) $this->body) |> $hash;
        }

        $ha2 = implode(':', $ha2Parts) |> $hash;

        // response
        $responseHash = implode(':', [$ha1, $c['nonce'], $ncHex, $cnonce, $qop, $ha2]) |> $hash;

        // build header
        $parts = [
            'username="'.str_replace(['\\', '"'], ['\\\\', '\\"'], $username).'"',
            'realm="'.$c['realm'].'"',
            'nonce="'.$c['nonce'].'"',
            'uri="'.$uri.'"',
            'algorithm='.$c['algorithm'],
            'qop='.$qop,
            'nc='.$ncHex,
            'cnonce="'.$cnonce.'"',
            'response="'.$responseHash.'"',
        ];

        if (isset($c['opaque'])) {
            $parts[] = 'opaque="'.$c['opaque'].'"';
        }

        return $this->withHeader('Authorization', 'Digest '.implode(', ', $parts));
    }

    /**
     * Returns the new Request instance with updated data.
     *
     * If the current `Content-Type` begins with `application/json`, `$data` is JSON-encoded
     * and written to the request body.
     *
     * Otherwise:
     * - If `$data` contains any {@see UploadedFileInterface} instances (or stream resources),
     *   it is encoded as `multipart/form-data`.
     * - Else it is encoded as `application/x-www-form-urlencoded`.
     *
     * Note: When encoding multipart data, stream resources are fully read into memory.
     *
     * @param array<string, mixed> $data The data.
     * @return static The new Request instance with the updated data.
     *
     * @throws JsonException If JSON encoding fails.
     */
    public function withData(array $data = []): static
    {
        $contentType = $this->getHeaderLine('Content-Type');

        if (str_starts_with($contentType, 'application/json')) {
            return json_encode($data, JSON_THROW_ON_ERROR)
                |> Stream::createFromString(...)
                |> $this->withBody(...);
        }

        $stream = Stream::createFromString('');

        if (static::hasFile($data)) {
            $boundary = random_bytes(16) |> md5(...);
            $contentType = 'multipart/form-data; boundary='.$boundary;

            static::addParts($stream, $data, $boundary);

            $stream->write('--'.$boundary.'--');
        } else {
            $contentType = 'application/x-www-form-urlencoded';
            http_build_query($data) |> $stream->write(...);
        }

        return $this
            ->withHeader('Content-Type', $contentType)
            ->withBody($stream);
    }

    /**
     * Returns the new Request instance with a Proxy-Authorization header.
     *
     * @param string $username The username.
     * @param string $password The password.
     * @return static The new Request instance with the Proxy-Authorization header.
     */
    public function withProxyAuth(string $username, string $password): static
    {
        return $this->withHeader('Proxy-Authorization', 'Basic '.base64_encode($username.':'.$password));
    }

    /**
     * Adds parts to a multipart stream.
     *
     * @param Stream $stream The Stream.
     * @param array<string, mixed> $data The data.
     * @param string $boundary The boundary.
     * @param string $prefix The prefix.
     */
    protected static function addParts($stream, array $data, string $boundary, string $prefix = ''): void
    {
        foreach ($data as $key => $value) {
            if ($prefix) {
                $key = $prefix.'['.$key.']';
            }

            if (is_array($value)) {
                static::addParts($stream, $value, $boundary, $key);

                continue;
            }

            $filename = null;
            $contentType = null;

            if ($value instanceof UploadedFileInterface) {
                $filename = $value->getClientFilename();
                $contentType = $value->getClientMediaType();
                $value = (string) $value->getStream();
            } else if (is_resource($value)) {
                $metadata = stream_get_meta_data($value);
                $uri = $metadata['uri'] ?? '';
                $finfo = new finfo(FILEINFO_MIME);
                $filename = basename($uri);
                $contentType = $finfo->file($uri);

                $value = (string) stream_get_contents($value);
            }

            $stream->write('--'.$boundary);
            $stream->write("\r\n");
            $stream->write('Content-Disposition: form-data');

            $stream->write('; name="'.static::prepareHeaderValue($key).'"');

            if ($filename) {
                $stream->write('; filename="'.static::prepareHeaderValue($filename).'"');
            }

            $stream->write("\r\n");

            if ($contentType) {
                $stream->write('Content-Type: '.$contentType);
                $stream->write("\r\n");
            }

            $stream->write("\r\n");
            $stream->write((string) $value);
            $stream->write("\r\n");
        }
    }

    /**
     * Checks whether the data has a file.
     *
     * @param array<string, mixed> $data The data.
     * @return bool Whether the data has a file.
     */
    protected static function hasFile(array $data): bool
    {
        foreach ($data as $value) {
            if ($value instanceof UploadedFileInterface or is_resource($value)) {
                return true;
            }

            if (is_array($value) && static::hasFile($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prepares a header value.
     *
     * @param string $value The value.
     * @return string The prepared value.
     */
    protected static function prepareHeaderValue(string $value): string
    {
        return str_replace('"', '', $value) |> Str::transliterate(...);
    }
}
