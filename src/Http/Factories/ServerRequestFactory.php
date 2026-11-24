<?php
declare(strict_types=1);

namespace Fyre\Http\Factories;

use Fyre\Core\Config;
use Fyre\DB\TypeParser;
use Fyre\Http\ServerRequest;
use Override;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

use function array_key_exists;
use function array_merge;
use function getenv;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function substr;
use function ucwords;

/**
 * Creates synthetic PSR-7 server requests and requests populated from PHP globals.
 */
class ServerRequestFactory implements ServerRequestFactoryInterface
{
    /**
     * Constructs a ServerRequestFactory.
     *
     * @param Config $config The Config.
     * @param TypeParser $typeParser The TypeParser.
     * @param StreamFactory $streamFactory The StreamFactory.
     * @param UploadedFileFactory $uploadedFileFactory The UploadedFileFactory.
     * @param UriFactory $uriFactory The UriFactory.
     */
    public function __construct(
        protected Config $config,
        protected TypeParser $typeParser,
        protected StreamFactory $streamFactory,
        protected UploadedFileFactory $uploadedFileFactory,
        protected UriFactory $uriFactory
    ) {}

    /**
     * Creates a ServerRequest from PHP superglobals.
     *
     * If an argument is null, the corresponding PHP superglobal is used.
     *
     * @param array<string, mixed>|null $server The server parameters.
     * @param array<string, mixed>|null $query The query parameters.
     * @param array<string, mixed>|null $parsedBody The parsed body.
     * @param array<string, mixed>|null $cookies The cookie parameters.
     * @param array<string, mixed>|null $files The uploaded files.
     * @return ServerRequest The new ServerRequest.
     */
    public function createFromGlobals(
        array|null $server = null,
        array|null $query = null,
        array|null $parsedBody = null,
        array|null $cookies = null,
        array|null $files = null
    ): ServerRequest {
        $server ??= $_SERVER;

        if (!isset($server['CONTENT_TYPE'])) {
            $contentType = getenv('CONTENT_TYPE');

            if ($contentType) {
                $server['CONTENT_TYPE'] = $contentType;
            }
        }

        $options = [
            'body' => $this->streamFactory->createStreamFromFile('php://input'),
            'cookies' => $cookies ?? $_COOKIE,
            'files' => $files ?? $_FILES,
            'get' => $query ?? $_GET,
            'server' => $server,
        ];

        if ($parsedBody !== null) {
            $options['data'] = $parsedBody;
        } else if ($_POST !== []) {
            $options['data'] = $_POST;
        }

        return $this->createFromOptions($options);
    }

    /**
     * Creates a Fyre ServerRequest from request options.
     *
     * Unlike {@see ServerRequestFactory::createFromGlobals()}, this method does not read PHP
     * superglobals. Server parameters are used to provide defaults for the method, headers,
     * and URI when those options are not supplied.
     *
     * @param array<string, mixed> $options The request options.
     * @return ServerRequest The new ServerRequest.
     */
    public function createFromOptions(array $options = []): ServerRequest
    {
        $serverParams = $options['server'] ?? [];

        $options['files'] = $this->uploadedFileFactory->createUploadedFiles($options['files'] ?? []);
        $options['method'] ??= $serverParams['REQUEST_METHOD'] ?? 'GET';
        $options['headers'] = array_merge(
            static::buildHeaders($serverParams),
            $options['headers'] ?? []
        );
        $options['body'] ??= '';

        $request = new ServerRequest($this->config, $this->typeParser, $options);

        if (!array_key_exists('uri', $options)) {
            $uri = $this->uriFactory->createFromServer(
                $serverParams,
                $request->getHeaderLine('Host'),
                $request->isSecure()
            );

            $request = $request->withUri($uri, true);
        }

        return $request;
    }

    /**
     * {@inheritDoc}
     *
     * @param string|UriInterface $uri The request URI.
     * @param array<string, mixed> $serverParams The server parameters.
     */
    #[Override]
    public function createServerRequest(string $method, $uri, array $serverParams = []): ServerRequestInterface
    {
        return $this->createFromOptions([
            'method' => $method,
            'server' => $serverParams,
            'uri' => $uri,
        ]);
    }

    /**
     * Builds headers from server parameters.
     *
     * @param array<string, mixed> $serverParams The server parameters.
     * @return array<string, mixed> The headers.
     */
    protected static function buildHeaders(array $serverParams): array
    {
        $headers = [];

        if (isset($serverParams['CONTENT_TYPE'])) {
            $headers['Content-Type'] = $serverParams['CONTENT_TYPE'];
        }

        if (isset($serverParams['CONTENT_LENGTH'])) {
            $headers['Content-Length'] = $serverParams['CONTENT_LENGTH'];
        }

        foreach ($serverParams as $key => $value) {
            if (!str_starts_with($key, 'HTTP_')) {
                continue;
            }

            $header = substr($key, 5)
                |> strtolower(...)
                |> str_replace('_', ' ', ?)
                |> ucwords(...)
                |> str_replace(' ', '-', ?);

            $headers[$header] = $value;
        }

        return $headers;
    }
}
