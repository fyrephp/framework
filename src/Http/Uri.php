<?php
declare(strict_types=1);

namespace Fyre\Http;

use Fyre\Core\Traits\DebugTrait;
use Fyre\Core\Traits\MacroTrait;
use Fyre\Utility\Arr;
use Override;
use Psr\Http\Message\UriInterface;
use Stringable;
use Uri\Rfc3986\Uri as Rfc3986Uri;

use function count;
use function explode;
use function getservbyname;
use function http_build_query;
use function parse_str;
use function preg_quote;
use function preg_replace_callback;
use function rawurlencode;
use function str_starts_with;
use function substr;
use function trim;

/**
 * Provides a PSR-7 {@see UriInterface} implementation that wraps an RFC 3986 URI object and
 * adds convenience helpers for query parameters and path segments.
 *
 * @phpstan-consistent-constructor
 */
class Uri implements Stringable, UriInterface
{
    use DebugTrait;
    use MacroTrait;

    /**
     * @var array<mixed>|null
     */
    protected array|null $queryParams = null;

    /**
     * @var string[]|null
     */
    protected array|null $segments = null;

    protected bool $showPassword = true;

    protected Rfc3986Uri $uri;

    /**
     * Creates a new Uri.
     *
     * @param string $uri The URI string.
     * @return static The new Uri instance.
     */
    public static function createFromString(string $uri = ''): static
    {
        return new static($uri);
    }

    /**
     * Constructs a Uri.
     *
     * @param string $uri The URI string.
     */
    public function __construct(string $uri = '')
    {
        $this->uri = new Rfc3986Uri($uri);
    }

    /**
     * Returns the URI string.
     *
     * @return string The URI string.
     */
    #[Override]
    public function __toString(): string
    {
        return $this->uri->toString();
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getAuthority(): string
    {
        $host = $this->getHost();

        if ($host === '') {
            return '';
        }

        $scheme = $this->getScheme();
        $port = $this->getPort();

        $result = $this->getUserInfo();

        if ($result !== '') {
            $result .= '@';
        }

        $result .= $host;

        if ($port !== null && $port !== getservbyname($scheme, 'tcp')) {
            $result .= ':'.$port;
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getFragment(): string
    {
        return $this->uri->getFragment() ?? '';
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getHost(): string
    {
        return $this->uri->getHost() ?? '';
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getPath(): string
    {
        return $this->uri->getPath();
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getPort(): int|null
    {
        return $this->uri->getPort();
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getQuery(): string
    {
        return $this->uri->getQuery() ?? '';
    }

    /**
     * Returns the URI query array.
     *
     * @return array<mixed> The URI query array.
     */
    public function getQueryParams(): array
    {
        if ($this->queryParams === null) {
            parse_str($this->getQuery(), $this->queryParams);
        }

        return $this->queryParams;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getScheme(): string
    {
        return $this->uri->getScheme() ?? '';
    }

    /**
     * Returns a specified URI segment.
     *
     * @param int $segment The URI segment index (1-based).
     * @return string The URI segment.
     */
    public function getSegment(int $segment): string
    {
        return $this->getSegments()[$segment - 1] ?? '';
    }

    /**
     * Returns the URI segments.
     *
     * @return string[] The URI segments.
     */
    public function getSegments(): array
    {
        if ($this->segments === null) {
            $path = $this->uri->getPath();
            $path = trim($path, '/');

            $this->segments = $path === '' ? [] : explode('/', $path);
        }

        return $this->segments;
    }

    /**
     * Returns the URI segments count.
     *
     * @return int The URI segments count.
     */
    public function getTotalSegments(): int
    {
        return $this->getSegments() |> count(...);
    }

    /**
     * Returns the URI string.
     *
     * @return string The URI.
     */
    public function getUri(): string
    {
        return $this->uri->toString();
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getUserInfo(): string
    {
        $result = $this->uri->getUsername() ?? '';
        $password = $this->uri->getPassword();

        if (
            $this->showPassword === true &&
            $password !== null &&
            $password !== ''
        ) {
            $result .= ':'.$password;
        }

        return $result;
    }

    /**
     * Returns the new Uri instance with a resolved relative URI.
     *
     * @param string $uri The URI string.
     * @return static The new Uri instance with the resolved relative URI.
     */
    public function resolveRelativeUri(string $uri): static
    {
        $temp = clone $this;

        $temp->uri = $temp->uri->resolve($uri);
        $temp->queryParams = null;
        $temp->segments = null;

        return $temp;
    }

    /**
     * Returns the new Uri instance with the updated query parameter.
     *
     * Note: This replaces any existing value for the key.
     *
     * @param string $key The key.
     * @param mixed $value The value.
     * @return static The new Uri instance with the updated query parameter.
     */
    public function withAddedQuery(string $key, mixed $value = null): static
    {
        $queryParams = $this->getQueryParams();

        $queryParams[$key] = $value;

        return $this->withQueryParams($queryParams);
    }

    /**
     * Returns the new Uri instance with the updated authority.
     *
     * @param string $authority The authority string.
     * @return static The new Uri instance with the updated authority.
     */
    public function withAuthority(string $authority): static
    {
        $scheme = $this->getScheme();
        $uri = $scheme !== '' ? $scheme.'://'.$authority : '//'.$authority;

        return new static($uri)
            ->withPath($this->getPath())
            ->withQuery($this->getQuery())
            ->withFragment($this->getFragment());
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function withFragment(string $fragment = ''): static
    {
        if (str_starts_with($fragment, '#')) {
            $fragment = substr($fragment, 1);
        }

        $fragment = static::encodeComponent($fragment, '?');

        $temp = clone $this;

        $temp->uri = $temp->uri->withFragment($fragment === '' ? null : $fragment);

        return $temp;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function withHost(string $host = ''): static
    {
        $temp = clone $this;

        $temp->uri = $temp->uri->withHost($host === '' ? null : $host);

        return $temp;
    }

    /**
     * Returns the new Uri instance with only specific query parameters.
     *
     * @param string[] $keys The query parameters to keep.
     * @return static The new Uri instance with only the specified query parameters.
     */
    public function withOnlyQuery(array $keys): static
    {
        return $this->getQueryParams()
            |> Arr::only(?, $keys)
            |> $this->withQueryParams(...);
    }

    /**
     * Returns the new Uri instance without specified query parameters.
     *
     * @param string[] $keys The query parameters to remove.
     * @return static The new Uri instance without the specified query parameters.
     */
    public function withoutQuery(array $keys): static
    {
        return $this->getQueryParams()
            |> Arr::except(?, $keys)
            |> $this->withQueryParams(...);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function withPath(string $path): static
    {
        $path = static::encodeComponent($path);

        $temp = clone $this;

        $temp->uri = $temp->uri->withPath($path);
        $temp->segments = null;

        return $temp;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function withPort(int|null $port = null): static
    {
        $temp = clone $this;

        $temp->uri = $temp->uri->withPort($port);

        return $temp;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function withQuery(string $query): static
    {
        if (str_starts_with($query, '?')) {
            $query = substr($query, 1);
        }

        $query = static::encodeComponent($query, '?');

        $temp = clone $this;

        $temp->uri = $temp->uri->withQuery($query === '' ? null : $query);
        $temp->queryParams = null;

        return $temp;
    }

    /**
     * Returns the new Uri instance with updated query parameters.
     *
     * @param array<string, mixed> $query The query array.
     * @return static The new Uri instance with the updated query parameters.
     */
    public function withQueryParams(array $query): static
    {
        return http_build_query($query) |> $this->withQuery(...);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function withScheme(string $scheme): static
    {
        $temp = clone $this;

        $temp->uri = $temp->uri->withScheme($scheme === '' ? null : $scheme);

        return $temp;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function withUserInfo(string $user, string|null $password = null): static
    {
        $temp = clone $this;

        $userInfo = $user;

        if ($password !== null && $password !== '') {
            $userInfo .= ':'.$password;
        }

        $temp->uri = $temp->uri->withUserInfo($userInfo);

        return $temp;
    }

    /**
     * Encodes characters outside the path character set, preserving valid percent escapes.
     *
     * @param string $value The component value.
     * @param string $extraCharacters Additional allowed characters.
     * @return string The encoded component.
     */
    protected static function encodeComponent(string $value, string $extraCharacters = ''): string
    {
        $extraCharacters = preg_quote($extraCharacters, '`');
        $pattern = '`[^0-9a-z_\-.~!$&\'()*+,;=:@/%'.$extraCharacters.']+|%(?![0-9a-f]{2})`i';

        return (string) preg_replace_callback(
            $pattern,
            static fn(array $match): string => rawurlencode($match[0]),
            $value
        );
    }
}
