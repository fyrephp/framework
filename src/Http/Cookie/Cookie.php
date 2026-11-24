<?php
declare(strict_types=1);

namespace Fyre\Http\Cookie;

use Fyre\Core\Traits\DebugTrait;
use InvalidArgumentException;
use Override;
use Stringable;

use function array_replace;
use function array_shift;
use function explode;
use function filter_var;
use function gmdate;
use function idn_to_ascii;
use function implode;
use function in_array;
use function ltrim;
use function min;
use function preg_match;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strtolower;
use function strtotime;
use function substr;
use function time;
use function trim;

use const FILTER_VALIDATE_IP;
use const IDNA_DEFAULT;
use const IDNA_USE_STD3_RULES;
use const INTL_IDNA_VARIANT_UTS46;

/**
 * Represents a cookie and can parse/format `Set-Cookie` header values.
 *
 * @phpstan-consistent-constructor
 */
class Cookie implements Stringable
{
    use DebugTrait;

    /**
     * @var array<string, mixed>
     */
    protected static array $defaults = [
        'expires' => null,
        'path' => '/',
        'domain' => '',
        'hostOnly' => false,
        'secure' => false,
        'httpOnly' => false,
        'sameSite' => 'lax',
    ];

    protected string $domain;

    protected bool $domainValid;

    protected int|null $expires;

    protected bool $hostOnly;

    protected bool $httpOnly;

    protected string $path;

    /**
     * @var 'lax'|'none'|'strict'
     */
    protected string $sameSite;

    protected bool $secure;

    /**
     * Creates a Cookie from a header string.
     *
     * Parses a `Set-Cookie` header value and applies any provided default options.
     * Header attributes are parsed case-insensitively.
     *
     * Note: `Max-Age` takes precedence over `Expires`.
     *
     * @param string $string The `Set-Cookie` header value.
     * @param array<string, mixed> $options The default cookie options to apply.
     * @return static The new Cookie instance.
     */
    public static function createFromHeaderString(string $string, array $options = []): static
    {
        $parts = explode(';', $string);
        $hasDomainAttribute = false;
        $defaultPath = (string) ($options['path'] ?? '/');

        $nameValue = array_shift($parts) |> explode('=', ?, 2);

        $name = array_shift($nameValue) |> trim(...);
        $value = (array_shift($nameValue) ?? '') |> trim(...);

        if (
            $value !== '"' &&
            str_starts_with($value, '"') &&
            str_ends_with($value, '"')
        ) {
            $value = substr($value, 1, -1);
        }

        $expires = null;
        $maxAge = null;

        foreach ($parts as $part) {
            if (str_contains($part, '=')) {
                [$key, $attributeValue] = explode('=', $part, 2);
                $attributeValue = trim($attributeValue);
            } else {
                $key = $part;
                $attributeValue = null;
            }

            $key = trim($key) |> strtolower(...);
            $key = match ($key) {
                'httponly' => 'httpOnly',
                'samesite' => 'sameSite',
                default => $key
            };

            if (in_array($key, ['secure', 'httpOnly'], true)) {
                $options[$key] = true;

                continue;
            }

            if ($attributeValue === null) {
                continue;
            }

            if ($key === 'expires') {
                $expires = $attributeValue;

                continue;
            }

            if ($key === 'max-age') {
                $maxAge = $attributeValue;

                continue;
            }

            if ($key === 'domain') {
                if (ltrim($attributeValue, '.') === '') {
                    continue;
                }

                $hasDomainAttribute = true;
            }

            $options[$key] = $attributeValue;
        }

        if ($expires !== null) {
            $timestamp = strtotime($expires);

            if ($timestamp !== false) {
                $options['expires'] = $timestamp;
            }
        }

        if ($maxAge !== null && preg_match('/\A-?\d+\z/', $maxAge)) {
            $maxAge = (int) $maxAge;
            $options['expires'] = min(PHP_INT_MAX, $maxAge + time());
        }

        $options['hostOnly'] = !$hasDomainAttribute;

        if (!isset($options['path']) || !str_starts_with((string) $options['path'], '/')) {
            $options['path'] = $defaultPath;
        }

        return new static($name, $value, $options);
    }

    /**
     * Normalizes a cookie domain for matching and storage.
     *
     * @param string $domain The cookie domain.
     * @return array{string, bool} The normalized domain and whether it is valid.
     */
    public static function normalizeDomain(string $domain): array
    {
        $domain = trim($domain)
            |> strtolower(...)
            |> ltrim(?, '.');

        if ($domain === '') {
            return ['', true];
        }

        if (str_ends_with($domain, '.')) {
            return [rtrim($domain, '.'), false];
        }

        $ipAddress = str_starts_with($domain, '[') && str_ends_with($domain, ']') ?
            substr($domain, 1, -1) :
            $domain;

        if (filter_var($ipAddress, FILTER_VALIDATE_IP) !== false) {
            return [$domain, true];
        }

        $ascii = idn_to_ascii($domain, IDNA_DEFAULT | IDNA_USE_STD3_RULES, INTL_IDNA_VARIANT_UTS46);

        return $ascii === false ?
            [$domain, false] :
            [$ascii, true];
    }

    /**
     * Constructs a Cookie.
     *
     * @param string $name The cookie name.
     * @param string $value The cookie value.
     * @param array<string, mixed> $options The options for the cookie.
     *
     * @throws InvalidArgumentException If the name, value, or same site option is not valid.
     */
    public function __construct(
        protected string $name,
        protected string $value = '',
        array $options = []
    ) {
        $options = array_replace(static::$defaults, $options);

        if ($this->name === '' || !preg_match('/\A[!#$%&\'*+\-.^_`|~0-9A-Za-z]+\z/', $this->name)) {
            throw new InvalidArgumentException('Cookie name is not valid.');
        }

        if (!preg_match('/\A[\x21\x23-\x2B\x2D-\x3A\x3C-\x5B\x5D-\x7E]*\z/', $this->value)) {
            throw new InvalidArgumentException('Cookie value is not valid.');
        }

        $this->expires = $options['expires'];
        $this->path = $options['path'];
        [$this->domain, $this->domainValid] = static::normalizeDomain($options['domain']);
        $this->hostOnly = $options['hostOnly'];
        $this->secure = $options['secure'];
        $this->httpOnly = $options['httpOnly'];

        $sameSite = strtolower((string) $options['sameSite']);

        if (!in_array($sameSite, ['lax', 'strict', 'none'], true)) {
            throw new InvalidArgumentException(sprintf(
                'Same site `%s` is not valid.',
                $sameSite
            ));
        }

        $this->sameSite = $sameSite;
    }

    /**
     * Returns the cookie header string.
     *
     * @return string The cookie header string.
     */
    #[Override]
    public function __toString(): string
    {
        return $this->toHeaderString();
    }

    /**
     * Returns the cookie domain.
     *
     * @return string The cookie domain.
     */
    public function getDomain(): string
    {
        return $this->domain;
    }

    /**
     * Returns the cookie expires timestamp.
     *
     * @return int|null The cookie expires timestamp.
     */
    public function getExpires(): int|null
    {
        return $this->expires;
    }

    /**
     * Returns the unique cookie identifier.
     *
     * The identifier is based on the cookie name, domain, path, and host-only state.
     *
     * @return string The unique cookie identifier.
     */
    public function getId(): string
    {
        return implode(',', [$this->name, $this->domain, $this->path, (int) $this->hostOnly]);
    }

    /**
     * Returns the cookie name.
     *
     * @return string The cookie name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Returns the cookie path.
     *
     * @return string The cookie path.
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Returns the cookie SameSite attribute.
     *
     * @return 'lax'|'none'|'strict' The cookie SameSite attribute.
     */
    public function getSameSite(): string
    {
        return $this->sameSite;
    }

    /**
     * Returns the cookie value.
     *
     * @return string The cookie value.
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * Checks whether the cookie domain could be canonicalized.
     *
     * @return bool Whether the cookie domain is valid.
     */
    public function isDomainValid(): bool
    {
        return $this->domainValid;
    }

    /**
     * Checks whether the cookie has expired.
     *
     * @return bool Whether the cookie has expired.
     */
    public function isExpired(): bool
    {
        return $this->expires !== null && $this->expires <= time();
    }

    /**
     * Checks whether the cookie is scoped to the exact host that created it.
     *
     * @return bool Whether the cookie is host-only.
     */
    public function isHostOnly(): bool
    {
        return $this->hostOnly;
    }

    /**
     * Checks whether the cookie is HTTP only.
     *
     * @return bool Whether the cookie is HTTP only.
     */
    public function isHttpOnly(): bool
    {
        return $this->httpOnly;
    }

    /**
     * Checks whether the cookie is secure.
     *
     * @return bool Whether the cookie is secure.
     */
    public function isSecure(): bool
    {
        return $this->secure;
    }

    /**
     * Returns the cookie header string.
     *
     * Formats a `Set-Cookie` header value with attribute tokens in lower-case (e.g. `secure`,
     * `httponly`, `samesite`).
     *
     * @return string The cookie header string.
     */
    public function toHeaderString(): string
    {
        $result = $this->name.'='.$this->value;

        if ($this->expires !== null) {
            $result .= '; expires='.gmdate('D, d M Y H:i:s T', $this->expires);
        }

        if ($this->path) {
            $result .= '; path='.$this->path;
        }

        if ($this->domain) {
            $result .= '; domain='.$this->domain;
        }

        if ($this->secure) {
            $result .= '; secure';
        }

        if ($this->httpOnly) {
            $result .= '; httponly';
        }

        if ($this->sameSite) {
            $result .= '; samesite='.$this->sameSite;
        }

        return $result;
    }
}
