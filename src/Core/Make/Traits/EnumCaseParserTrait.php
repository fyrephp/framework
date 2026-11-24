<?php
declare(strict_types=1);

namespace Fyre\Core\Make\Traits;

use InvalidArgumentException;

use function array_all;
use function array_filter;
use function array_key_exists;
use function array_map;
use function explode;
use function preg_match;
use function trim;

/**
 * Provides enum case parsing.
 *
 * @internal
 *
 * @phpstan-type ParsedEnumCases (
 *     array{cases: array<string, null>, stringBacked: false}|
 *     array{cases: array<string, string>, stringBacked: true}
 * )
 */
trait EnumCaseParserTrait
{
    /**
     * Parses comma-separated enum cases.
     *
     * Missing values are inferred from the case name when any case defines a string value.
     *
     * @param string|null $cases The enum cases.
     * @return ParsedEnumCases The parsed enum cases.
     *
     * @throws InvalidArgumentException If a case is invalid or duplicated.
     */
    protected function parseEnumCases(string|null $cases): array
    {
        $cases = explode(',', $cases ?? '')
            |> array_map(trim(...), ?)
            |> array_filter(?, static fn($case) => $case !== '');

        if ($cases === []) {
            return [
                'cases' => [],
                'stringBacked' => false,
            ];
        }

        $parsedCases = [];

        foreach ($cases as $definition) {
            $segments = explode(':', $definition, 2);
            $name = trim($segments[0]);

            if (!preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $name)) {
                throw new InvalidArgumentException('Invalid enum case.');
            }

            if (array_key_exists($name, $parsedCases)) {
                throw new InvalidArgumentException('Duplicate enum case.');
            }

            $value = isset($segments[1]) ?
                trim($segments[1]) :
                null;

            $parsedCases[$name] = $value;
        }

        if (array_all($parsedCases, static fn(string|null $value): bool => $value === null)) {
            /** @var array<string, null> $parsedCases */
            return [
                'cases' => $parsedCases,
                'stringBacked' => false,
            ];
        }

        $backedCases = [];
        foreach ($parsedCases as $name => $value) {
            $backedCases[$name] = $value === null || $value === '' ?
                $this->inflector->underscore($name) :
                $value;
        }

        return [
            'cases' => $backedCases,
            'stringBacked' => true,
        ];
    }
}
