<?php
declare(strict_types=1);

namespace Fyre\Form;

use Closure;
use Fyre\Core\Traits\DebugTrait;
use Fyre\Core\Traits\StaticMacroTrait;
use Fyre\DB\TypeParser;

use function array_key_exists;
use function ctype_alnum;
use function ctype_alpha;
use function ctype_digit;
use function ctype_print;
use function filter_var;
use function implode;
use function in_array;
use function is_scalar;
use function preg_match;
use function strlen;
use function strtolower;

use const FILTER_FLAG_EMAIL_UNICODE;
use const FILTER_FLAG_IPV4;
use const FILTER_FLAG_IPV6;
use const FILTER_NULL_ON_FAILURE;
use const FILTER_VALIDATE_BOOLEAN;
use const FILTER_VALIDATE_EMAIL;
use const FILTER_VALIDATE_FLOAT;
use const FILTER_VALIDATE_INT;
use const FILTER_VALIDATE_IP;

/**
 * Defines a single validation rule.
 *
 * Rule callbacks should return `true` for pass, `false` for failure (using the rule
 * message), or a string to provide a custom failure message.
 *
 * Rules can be configured to skip validation when a field is empty and/or not set.
 * Callbacks are invoked by the container with named context arguments, so callbacks may
 * declare only the values and services they need.
 *
 * @phpstan-consistent-constructor
 *
 * @phpstan-type RuleCallback Closure
 */
class Rule
{
    use DebugTrait;
    use StaticMacroTrait;

    protected string|null $message = null;

    protected string|null $type = null;

    /**
     * Creates an "alpha" Rule.
     *
     * @return static The Rule.
     */
    public static function alpha(): static
    {
        return new static(
            static fn(mixed $value): bool => is_scalar($value) && ctype_alpha((string) $value),
            __FUNCTION__
        );
    }

    /**
     * Creates an "alpha-numeric" Rule.
     *
     * @return static The Rule.
     */
    public static function alphaNumeric(): static
    {
        return new static(
            static fn(mixed $value): bool => is_scalar($value) && ctype_alnum((string) $value),
            __FUNCTION__
        );
    }

    /**
     * Creates an "ASCII" Rule.
     *
     * @return static The Rule.
     */
    public static function ascii(): static
    {
        return new static(
            static fn(mixed $value): bool => is_scalar($value) && ctype_print((string) $value),
            __FUNCTION__
        );
    }

    /**
     * Creates a "between" Rule.
     *
     * @param int $min The minimum value.
     * @param int $max The maximum value.
     * @return static The Rule.
     */
    public static function between(int $min, int $max): static
    {
        return new static(
            static fn(mixed $value): bool => $value >= $min && $value <= $max,
            __FUNCTION__,
            [$min, $max]
        );
    }

    /**
     * Creates a "boolean" Rule.
     *
     * @return static The Rule.
     */
    public static function boolean(): static
    {
        return new static(
            static fn(mixed $value): bool => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== null,
            __FUNCTION__
        );
    }

    /**
     * Creates a "date" Rule.
     *
     * @return static The Rule.
     */
    public static function date(): static
    {
        return new static(
            static function(mixed $value, TypeParser $typeParser): bool {
                return $typeParser->use('date')->parse($value) !== null;
            },
            __FUNCTION__
        );
    }

    /**
     * Creates a "date/time" Rule.
     *
     * @return static The Rule.
     */
    public static function dateTime(): static
    {
        return new static(
            static function(mixed $value, TypeParser $typeParser): bool {
                return $typeParser->use('datetime')->parse($value) !== null;
            },
            __FUNCTION__
        );
    }

    /**
     * Creates a "decimal" Rule.
     *
     * @return static The Rule.
     */
    public static function decimal(): static
    {
        return new static(
            static fn(mixed $value): bool => filter_var($value, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE) !== null,
            __FUNCTION__
        );
    }

    /**
     * Creates a "differs" Rule.
     *
     * @param string $field The other field name.
     * @return static The Rule.
     */
    public static function differs(string $field): static
    {
        return new static(
            static fn(mixed $value, array $data): bool => $value !== ($data[$field] ?? null),
            __FUNCTION__,
            [$field]
        );
    }

    /**
     * Creates an "email" Rule.
     *
     * @return static The Rule.
     */
    public static function email(): static
    {
        return new static(
            static fn(mixed $value): bool => filter_var($value, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE | FILTER_NULL_ON_FAILURE) !== null,
            __FUNCTION__
        );
    }

    /**
     * Creates an "empty" Rule.
     *
     * Note: This rule fails when evaluated; with the default skip behavior it effectively
     * enforces that the field must be empty or not set.
     *
     * @return static The Rule.
     */
    public static function empty(): static
    {
        return new static(
            static fn(): bool => false,
            __FUNCTION__
        );
    }

    /**
     * Creates an "equals" Rule.
     *
     * @param mixed $other The value to compare against.
     * @return static The Rule.
     */
    public static function equals(mixed $other): static
    {
        return new static(
            static fn(mixed $value): bool => $value == $other,
            __FUNCTION__,
            [$other]
        );
    }

    /**
     * Creates an "exact length" Rule.
     *
     * @param int $length The length.
     * @return static The Rule.
     */
    public static function exactLength(int $length): static
    {
        return new static(
            static fn(mixed $value): bool => strlen((string) $value) === $length,
            __FUNCTION__,
            [$length]
        );
    }

    /**
     * Creates a "greater than" Rule.
     *
     * @param int $min The minimum value.
     * @return static The Rule.
     */
    public static function greaterThan(int $min): static
    {
        return new static(
            static fn(mixed $value): bool => $value > $min,
            __FUNCTION__,
            [$min]
        );
    }

    /**
     * Creates a "greater than or equals" Rule.
     *
     * @param int $min The minimum value.
     * @return static The Rule.
     */
    public static function greaterThanOrEquals(int $min): static
    {
        return new static(
            static fn(mixed $value): bool => $value >= $min,
            __FUNCTION__,
            [$min]
        );
    }

    /**
     * Creates an "in" Rule.
     *
     * @param array<bool|float|int|string> $values The values.
     * @return static The Rule.
     */
    public static function in(array $values): static
    {
        return new static(
            static fn(mixed $value): bool => in_array($value, $values, true),
            __FUNCTION__,
            [implode(', ', $values)]
        );
    }

    /**
     * Creates an "integer" Rule.
     *
     * @return static The Rule.
     */
    public static function integer(): static
    {
        return new static(
            static fn(mixed $value): bool => filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE) !== null,
            __FUNCTION__
        );
    }

    /**
     * Creates an "IP" Rule.
     *
     * @return static The Rule.
     */
    public static function ip(): static
    {
        return new static(
            static fn(mixed $value): bool => filter_var($value, FILTER_VALIDATE_IP, FILTER_NULL_ON_FAILURE) !== null,
            __FUNCTION__
        );
    }

    /**
     * Creates an "IPv4" Rule.
     *
     * @return static The Rule.
     */
    public static function ipv4(): static
    {
        return new static(
            static fn(mixed $value): bool => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_NULL_ON_FAILURE) !== null,
            __FUNCTION__
        );
    }

    /**
     * Creates an "IPv6" Rule.
     *
     * @return static The Rule.
     */
    public static function ipv6(): static
    {
        return new static(
            static fn(mixed $value): bool => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_NULL_ON_FAILURE) !== null,
            __FUNCTION__
        );
    }

    /**
     * Creates a "less than" Rule.
     *
     * @param int $max The maximum value.
     * @return static The Rule.
     */
    public static function lessThan(int $max): static
    {
        return new static(
            static fn(mixed $value): bool => $value < $max,
            __FUNCTION__,
            [$max]
        );
    }

    /**
     * Creates a "less than or equals" Rule.
     *
     * @param int $max The maximum value.
     * @return static The Rule.
     */
    public static function lessThanOrEquals(int $max): static
    {
        return new static(
            static fn(mixed $value): bool => $value <= $max,
            __FUNCTION__,
            [$max]
        );
    }

    /**
     * Creates a "matches" Rule.
     *
     * @param string $field The other field name.
     * @return static The Rule.
     */
    public static function matches(string $field): static
    {
        return new static(
            static fn(mixed $value, array $data): bool => $value === ($data[$field] ?? null),
            __FUNCTION__,
            [$field]
        );
    }

    /**
     * Creates a "maximum length" Rule.
     *
     * @param int $length The length.
     * @return static The Rule.
     */
    public static function maxLength(int $length): static
    {
        return new static(
            static fn(mixed $value): bool => strlen((string) $value) <= $length,
            __FUNCTION__,
            [$length]
        );
    }

    /**
     * Creates a "minimum length" Rule.
     *
     * @param int $length The length.
     * @return static The Rule.
     */
    public static function minLength(int $length): static
    {
        return new static(
            static fn(mixed $value): bool => strlen((string) $value) >= $length,
            __FUNCTION__,
            [$length]
        );
    }

    /**
     * Creates a "natural number" Rule.
     *
     * @return static The Rule.
     */
    public static function naturalNumber(): static
    {
        return new static(
            static fn(mixed $value): bool => is_scalar($value) && ctype_digit((string) $value),
            __FUNCTION__
        );
    }

    /**
     * Creates a "not empty" Rule.
     *
     * @return static The Rule.
     */
    public static function notEmpty(): static
    {
        return new static(
            static fn(mixed $value): bool => $value !== null && $value !== '' && $value !== [],
            __FUNCTION__,
            skipEmpty: false
        );
    }

    /**
     * Creates a "regular expression" Rule.
     *
     * @param string $regex The regular expression.
     * @return static The Rule.
     */
    public static function regex(string $regex): static
    {
        return new static(
            static fn(mixed $value): bool => preg_match($regex, (string) $value) === 1,
            __FUNCTION__,
            [$regex]
        );
    }

    /**
     * Creates a "required" Rule.
     *
     * Note: This requires the field to be present and not an empty string/array. A `null`
     * value is treated as missing.
     *
     * @return static The Rule.
     */
    public static function required(): static
    {
        return new static(
            static fn(mixed $value, array $data, string $field): bool => isset($data[$field]) &&
                $value !== '' &&
                $value !== [],
            __FUNCTION__,
            skipEmpty: false,
            skipNotSet: false
        );
    }

    /**
     * Creates a "require presence" Rule.
     *
     * Note: This checks presence using {@see array_key_exists()}, so `null` counts as
     * present.
     *
     * @return static The Rule.
     */
    public static function requirePresence(): static
    {
        return new static(
            static fn(array $data, string $field): bool => array_key_exists($field, $data),
            __FUNCTION__,
            skipEmpty: false,
            skipNotSet: false
        );
    }

    /**
     * Creates a "time" Rule.
     *
     * @return static The Rule.
     */
    public static function time(): static
    {
        return new static(
            static function(mixed $value, TypeParser $typeParser): bool {
                return $typeParser->use('time')->parse($value) !== null;
            },
            __FUNCTION__
        );
    }

    /**
     * Creates a "URL" Rule.
     *
     * @return static The Rule.
     */
    public static function url(): static
    {
        return new static(
            static fn(mixed $value): bool => filter_var($value, FILTER_VALIDATE_URL, FILTER_NULL_ON_FAILURE) !== null,
            __FUNCTION__
        );
    }

    /**
     * Constructs a Rule.
     *
     * @param RuleCallback $callback The callback.
     * @param string|null $name The rule name.
     * @param array<mixed> $arguments The callback arguments.
     * @param bool $skipEmpty Whether to skip validation for empty values.
     * @param bool $skipNotSet Whether to skip validation for unset values.
     */
    public function __construct(
        protected Closure $callback,
        protected string|null $name = null,
        protected array $arguments = [],
        protected bool $skipEmpty = true,
        protected bool $skipNotSet = true
    ) {}

    /**
     * Checks the type of rule.
     *
     * @param string|null $type The type to test.
     * @return bool Whether the types match.
     */
    public function checkType(string|null $type = null): bool
    {
        return !$type || !$this->type || strtolower($type) === $this->type;
    }

    /**
     * Returns the callback arguments.
     *
     * @return array<mixed> The callback arguments.
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    /**
     * Returns the callback.
     *
     * @return Closure The callback.
     */
    public function getCallback(): Closure
    {
        return $this->callback;
    }

    /**
     * Returns the rule error message.
     *
     * @return string|null The error message.
     */
    public function getMessage(): string|null
    {
        return $this->message;
    }

    /**
     * Returns the rule name.
     *
     * @return string|null The rule name.
     */
    public function getName(): string|null
    {
        return $this->name;
    }

    /**
     * Sets the rule error message.
     *
     * @param string $message The error message.
     * @return static The Rule instance.
     */
    public function setMessage(string $message): static
    {
        $this->message = $message;

        return $this;
    }

    /**
     * Sets the rule name.
     *
     * @param string $name The rule name.
     * @return static The Rule instance.
     */
    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Sets the rule type.
     *
     * @param string $type The rule type.
     * @return static The Rule instance.
     */
    public function setType(string $type): static
    {
        $this->type = strtolower($type);

        return $this;
    }

    /**
     * Checks whether to skip empty values.
     *
     * @return bool Whether empty values can be skipped.
     */
    public function skipEmpty(): bool
    {
        return $this->skipEmpty;
    }

    /**
     * Checks whether to skip unset values.
     *
     * @return bool Whether unset values can be skipped.
     */
    public function skipNotSet(): bool
    {
        return $this->skipNotSet;
    }
}
