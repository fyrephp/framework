<?php
declare(strict_types=1);

namespace Fyre\Security;

use Fyre\Core\Config;
use Fyre\Core\Container;
use Fyre\Core\Traits\DebugTrait;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function array_diff;
use function array_intersect;
use function array_map;
use function array_merge;
use function array_replace;
use function array_unshift;
use function explode;
use function implode;
use function in_array;
use function strtolower;
use function strtoupper;
use function trim;

/**
 * Applies Cross-Origin Resource Sharing (CORS) policy decisions and response headers.
 */
class Cors
{
    use DebugTrait;

    /**
     * @var array<string, mixed>
     */
    protected static array $defaults = [
        'allowCredentials' => false,
        'allowedHeaders' => [],
        'allowedMethods' => [
            'GET',
            'HEAD',
            'POST',
            'PUT',
            'PATCH',
            'DELETE',
        ],
        'allowedOrigins' => [],
        'exposedHeaders' => [],
        'maxAge' => null,
        'skipCheck' => null,
    ];

    /**
     * @var array<string, mixed>
     */
    protected array $options;

    /**
     * Constructs a Cors.
     *
     * @param Container $container The Container.
     * @param Config $config The Config.
     * @param array<string, mixed>|null $options The CORS options.
     */
    public function __construct(
        protected Container $container,
        Config $config,
        array|null $options = null
    ) {
        $options ??= $config->get('Cors', []);
        $options = array_replace(static::$defaults, $options);

        $this->options = $options;
    }

    /**
     * Adds CORS headers to a Response.
     *
     * @param ServerRequestInterface $request The ServerRequest.
     * @param ResponseInterface $response The Response.
     * @return ResponseInterface The new Response.
     */
    public function addHeaders(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->canHandleRequest($request)) {
            return $response;
        }

        $headers = [];

        if (!$this->isRequestAllowed($request)) {
            $headers['Vary'] = array_merge($response->getHeader('Vary'), ['Origin']);

            return $this->applyHeaders($response, $headers);
        }

        // Credentialed responses must echo the request origin instead of using a wildcard.
        if (
            in_array('*', $this->options['allowedOrigins'], true) &&
            !$this->options['allowCredentials']
        ) {
            $headers['Access-Control-Allow-Origin'] = '*';
        } else {
            $headers['Vary'] = array_merge($response->getHeader('Vary'), ['Origin']);
            $headers['Access-Control-Allow-Origin'] = $request->getHeaderLine('Origin');
        }

        if ($this->options['allowCredentials']) {
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }

        if ($this->options['exposedHeaders'] !== []) {
            $headers['Access-Control-Expose-Headers'] = implode(', ', $this->options['exposedHeaders']);
        }

        return $this->applyHeaders($response, $headers);
    }

    /**
     * Adds CORS headers to a preflight Response.
     *
     * @param ServerRequestInterface $request The ServerRequest.
     * @param ResponseInterface $response The Response.
     * @return ResponseInterface The new Response.
     */
    public function addHeadersPreflight(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->canHandleRequest($request)) {
            return $response;
        }

        // Preflight caches always vary by requested method and headers, and by origin when echoed.
        $headers = [];
        $headers['Vary'] = [
            'Access-Control-Request-Method',
            'Access-Control-Request-Headers',
        ];

        $allowAnyOrigin = in_array('*', $this->options['allowedOrigins'], true) &&
            !$this->options['allowCredentials'];

        if (!$allowAnyOrigin) {
            array_unshift($headers['Vary'], 'Origin');
        }

        if (!$this->isPreflightRequestAllowed($request)) {
            return $this->applyHeaders($response, $headers);
        }

        if ($allowAnyOrigin) {
            $headers['Access-Control-Allow-Origin'] = '*';
        } else {
            $headers['Access-Control-Allow-Origin'] = $request->getHeaderLine('Origin');
        }

        if ($this->options['allowCredentials']) {
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }

        $headers['Access-Control-Allow-Methods'] = in_array('*', $this->options['allowedMethods'], true) ?
            $request->getHeaderLine('Access-Control-Request-Method') :
            implode(', ', $this->options['allowedMethods']);

        $allowedHeaders = in_array('*', $this->options['allowedHeaders'], true) ?
            $request->getHeaderLine('Access-Control-Request-Headers') :
            implode(', ', $this->options['allowedHeaders']);

        if ($allowedHeaders !== '') {
            $headers['Access-Control-Allow-Headers'] = $allowedHeaders;
        }

        if ($this->options['maxAge'] !== null) {
            $headers['Access-Control-Max-Age'] = (string) $this->options['maxAge'];
        }

        return $this->applyHeaders($response, $headers);
    }

    /**
     * Checks whether CORS processing is enabled for a request.
     *
     * @param ServerRequestInterface $request The ServerRequest.
     * @return bool Whether the request should be processed as a CORS request.
     */
    public function canHandleRequest(ServerRequestInterface $request): bool
    {
        return $request->getHeaderLine('Origin') !== '' &&
            $this->options['allowedOrigins'] !== [];
    }

    /**
     * Checks whether a request is a CORS preflight request.
     *
     * @param ServerRequestInterface $request The ServerRequest.
     * @return bool Whether the request is a preflight request.
     */
    public function isPreflightRequest(ServerRequestInterface $request): bool
    {
        return $request->getMethod() === 'OPTIONS' &&
            $request->getHeaderLine('Access-Control-Request-Method') !== '';
    }

    /**
     * Checks whether CORS processing should be skipped for a request.
     *
     * @param ServerRequestInterface $request The ServerRequest.
     * @return bool Whether CORS processing should be skipped.
     */
    public function shouldSkip(ServerRequestInterface $request): bool
    {
        return $this->options['skipCheck'] &&
            $this->container->call($this->options['skipCheck'], ['request' => $request]) === true;
    }

    /**
     * Applies headers to a Response.
     *
     * @param ResponseInterface $response The Response.
     * @param array<string, string|string[]> $headers The headers.
     * @return ResponseInterface The new Response.
     */
    protected function applyHeaders(ResponseInterface $response, array $headers): ResponseInterface
    {
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * Checks whether a CORS preflight request is allowed by the configured policy.
     *
     * @param ServerRequestInterface $request The ServerRequest.
     * @return bool Whether the preflight request is allowed.
     */
    protected function isPreflightRequestAllowed(ServerRequestInterface $request): bool
    {
        if (!$this->isPreflightRequest($request) || !$this->isRequestAllowed($request)) {
            return false;
        }

        $method = $request->getHeaderLine('Access-Control-Request-Method')
            |> strtoupper(...);
        $methods = array_map(strtoupper(...), $this->options['allowedMethods']);

        if (array_intersect(['*', $method], $methods) === []) {
            return false;
        }

        $allowedHeaders = array_map(strtolower(...), $this->options['allowedHeaders']);

        if (in_array('*', $allowedHeaders, true)) {
            return true;
        }

        $headers = $request->getHeaderLine('Access-Control-Request-Headers');
        $headers = $headers !== '' ?
            explode(',', $headers)
                |> array_map(
                    static fn(string $header): string => trim($header) |> strtolower(...),
                    ?
                ) :
            [];

        return array_diff($headers, $allowedHeaders) === [];
    }

    /**
     * Checks whether a CORS request is allowed by the configured policy.
     *
     * @param ServerRequestInterface $request The ServerRequest.
     * @return bool Whether the request is allowed.
     */
    protected function isRequestAllowed(ServerRequestInterface $request): bool
    {
        $origin = $request->getHeaderLine('Origin');

        return array_intersect(['*', $origin], $this->options['allowedOrigins']) !== [];
    }
}
