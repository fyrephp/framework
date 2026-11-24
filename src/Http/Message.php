<?php
declare(strict_types=1);

namespace Fyre\Http;

use Fyre\Core\Traits\DebugTrait;
use InvalidArgumentException;
use Override;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\StreamInterface;
use Stringable;

use function array_combine;
use function array_keys;
use function array_map;
use function gettype;
use function implode;
use function in_array;
use function is_numeric;
use function is_string;
use function preg_match;
use function sprintf;
use function strtolower;
use function trim;

/**
 * Provides a base implementation of PSR-7 {@see MessageInterface} with header and body
 * handling. Header names are treated case-insensitively while preserving original name
 * casing for {@see Message::getHeaders()}.
 */
class Message implements MessageInterface
{
    use DebugTrait;

    protected const VALID_PROTOCOLS = [
        '1.0',
        '1.1',
        '2.0',
    ];

    protected StreamInterface $body;

    /**
     * @var array<string, string>
     */
    protected array $headerNames = [];

    /**
     * @var array<string, string[]>
     */
    protected array $headers = [];

    protected string $protocolVersion = '1.1';

    /**
     * Constructs a Message.
     *
     * @param array<string, mixed> $options The message options.
     *
     * @throws InvalidArgumentException If the body type is not valid.
     */
    public function __construct(array $options = [])
    {
        $options['body'] ??= '';
        $options['headers'] ??= [];
        $options['protocolVersion'] ??= '1.1';

        if ($options['body'] instanceof StreamInterface) {
            $this->body = $options['body'];
        } else if (is_string($options['body']) || $options['body'] instanceof Stringable) {
            $this->body = Stream::createFromString((string) $options['body']);
        } else {
            throw new InvalidArgumentException(sprintf(
                'Body value must be a string or instance of `%s`. Given value: %s',
                StreamInterface::class,
                gettype($options['body'])
            ));
        }

        foreach ($options['headers'] as $name => $value) {
            $filteredName = static::filterHeaderName($name);

            $this->headerNames[$filteredName] = $name;
            $this->headers[$filteredName] = static::filterHeaderValue($value);
        }

        $this->protocolVersion = static::filterProtocolVersion($options['protocolVersion']);
    }

    /**
     * Returns the message body.
     *
     * @return StreamInterface The Stream instance.
     */
    #[Override]
    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    /**
     * Returns the values of a message header.
     *
     * @param string $name The header name.
     * @return string[] The header values.
     */
    #[Override]
    public function getHeader(string $name): array
    {
        $name = strtolower($name);

        return $this->headers[$name] ?? [];
    }

    /**
     * Returns the value string of a message header.
     *
     * @param string $name The header name.
     * @return string The header value string.
     */
    #[Override]
    public function getHeaderLine(string $name): string
    {
        $name = strtolower($name);

        $value = $this->headers[$name] ?? [];

        return implode(', ', $value);
    }

    /**
     * Returns the message headers.
     *
     * @return array<string, string[]> The message headers.
     */
    #[Override]
    public function getHeaders(): array
    {
        if ($this->headers === []) {
            return [];
        }

        return array_keys($this->headers)
            |> array_map(fn(string $name): string => $this->headerNames[$name], ?)
            |> array_combine(?, $this->headers);
    }

    /**
     * Returns the protocol version.
     *
     * @return string The protocol version.
     */
    #[Override]
    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    /**
     * Checks whether the message has a header.
     *
     * @param string $name The header name.
     * @return bool Whether the message has the header.
     */
    #[Override]
    public function hasHeader(string $name): bool
    {
        $name = strtolower($name);

        return isset($this->headers[$name]);
    }

    /**
     * Returns the new Message instance with value(s) added to a header.
     *
     * Values are filtered and normalized. If the header does not exist it is created.
     *
     * @param string $name The header name.
     * @param mixed $value The header value.
     * @return static The new Message instance with the added header values.
     */
    #[Override]
    public function withAddedHeader(string $name, mixed $value): static
    {
        $filteredName = static::filterHeaderName($name);

        if (!isset($this->headers[$filteredName])) {
            return $this->withHeader($name, $value);
        }

        $temp = clone $this;

        $temp->headers[$filteredName] = [
            ...$temp->headers[$filteredName],
            ...static::filterHeaderValue($value),
        ];

        return $temp;
    }

    /**
     * Returns the new Message instance with the updated body.
     *
     * @param StreamInterface $body The Stream representing the message body.
     * @return static The new Message instance with the updated body.
     */
    #[Override]
    public function withBody(StreamInterface $body): static
    {
        $temp = clone $this;

        $temp->body = $body;

        return $temp;
    }

    /**
     * Returns the new Message instance with the updated header.
     *
     * Values are filtered and normalized and any existing values are replaced.
     *
     * @param string $name The header name.
     * @param mixed $value The header value.
     * @return static The new Message instance with the updated header.
     */
    #[Override]
    public function withHeader(string $name, mixed $value): static
    {
        $temp = clone $this;

        $filteredName = static::filterHeaderName($name);

        $temp->headerNames[$filteredName] = $name;
        $temp->headers[$filteredName] = static::filterHeaderValue($value);

        return $temp;
    }

    /**
     * Returns the new Message instance without the header.
     *
     * @param string $name The header name.
     * @return static The new Message instance without the header.
     */
    #[Override]
    public function withoutHeader(string $name): static
    {
        $temp = clone $this;

        $name = strtolower($name);

        unset($temp->headerNames[$name], $temp->headers[$name]);

        return $temp;
    }

    /**
     * Returns the new Message instance with the updated protocol version.
     *
     * @param string $version The protocol version.
     * @return static The new Message instance with the updated protocol version.
     */
    #[Override]
    public function withProtocolVersion(string $version): static
    {
        $temp = clone $this;

        $temp->protocolVersion = static::filterProtocolVersion($version);

        return $temp;
    }

    /**
     * Filters a header name.
     *
     * @param string $name The header name.
     * @return string The filtered header name.
     *
     * @throws InvalidArgumentException If the header name is not valid.
     */
    protected static function filterHeaderName(string $name): string
    {
        if (!preg_match('/\A[!#$%&\'*+\-.^_`|~0-9A-Za-z]+\z/', $name)) {
            throw new InvalidArgumentException(sprintf(
                'Header name `%s` is not valid.',
                $name
            ));
        }

        return strtolower($name);
    }

    /**
     * Filters a header value.
     *
     * @param mixed $value The header value.
     * @return string[] The filtered header value(s).
     *
     * @throws InvalidArgumentException If the header value is not valid.
     */
    protected static function filterHeaderValue(mixed $value): array
    {
        $values = (array) $value;

        if ($values === []) {
            throw new InvalidArgumentException('Header value cannot be empty.');
        }

        $values = array_map(
            static function(mixed $value): string {
                if (!is_string($value) && !is_numeric($value)) {
                    throw new InvalidArgumentException(sprintf(
                        'Header value must be a string or number. Given value: %s',
                        gettype($value)
                    ));
                }

                $value = (string) $value;

                if (preg_match('/[^\x09\x20-\x7E]/', $value)) {
                    throw new InvalidArgumentException(sprintf(
                        'Header value `%s` is not valid.',
                        $value
                    ));
                }

                return trim($value, " \t");
            },
            $values
        );

        return $values;
    }

    /**
     * Filters the protocol version.
     *
     * @param string $version The protocol version.
     * @return string The filtered protocol version.
     *
     * @throws InvalidArgumentException If the protocol version is not valid.
     */
    protected static function filterProtocolVersion(string $version): string
    {
        if (!in_array($version, static::VALID_PROTOCOLS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Protocol version `%s` is not valid.',
                $version
            ));
        }

        return $version;
    }
}
