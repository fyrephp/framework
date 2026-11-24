<?php
declare(strict_types=1);

namespace Fyre\Utility;

use Closure;
use Fyre\Core\Traits\DebugTrait;
use Fyre\Core\Traits\MacroTrait;

use function array_keys;
use function array_map;
use function array_merge;
use function array_replace;
use function array_search;
use function implode;
use function in_array;
use function lcfirst;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function str_replace;
use function strtolower;
use function ucwords;

/**
 * Provides inflection utilities.
 *
 * Includes pluralization/singularization rules with support for irregular and uncountable words, and caches computed
 * results for repeated calls.
 */
class Inflector
{
    use DebugTrait;
    use MacroTrait;

    /**
     * @var array<string, array<string, string>>
     */
    protected array $cache = [];

    /**
     * @var array<string, string>
     */
    protected array $irregular = [
        'alias' => 'aliases',
        'atlas' => 'atlases',
        'beef' => 'beefs',
        'brief' => 'briefs',
        'brother' => 'brothers',
        'bus' => 'buses',
        'cafe' => 'cafes',
        'cache' => 'caches',
        'chef' => 'chefs',
        'child' => 'children',
        'cookie' => 'cookies',
        'corpus' => 'corpuses',
        'cow' => 'cows',
        'criterion' => 'criteria',
        'drive' => 'drives',
        'foot' => 'feet',
        'foe' => 'foes',
        'gas' => 'gases',
        'ganglion' => 'ganglions',
        'genie' => 'genies',
        'genus' => 'genera',
        'goose' => 'geese',
        'graffito' => 'graffiti',
        'hero' => 'heroes',
        'hive' => 'hives',
        'hoof' => 'hoofs',
        'index' => 'indices',
        'leaf' => 'leaves',
        'loaf' => 'loaves',
        'man' => 'men',
        'matrix' => 'matrices',
        'money' => 'monies',
        'mongoose' => 'mongooses',
        'move' => 'moves',
        'mythos' => 'mythoi',
        'niche' => 'niches',
        'numen' => 'numina',
        'occiput' => 'occiputs',
        'octopus' => 'octopuses',
        'opus' => 'opuses',
        'ox' => 'oxen',
        'penis' => 'penises',
        'person' => 'people',
        'potato' => 'potatoes',
        'quiz' => 'quizzes',
        'sex' => 'sexes',
        'shoe' => 'shoes',
        'sieve' => 'sieves',
        'soliloquy' => 'soliloquies',
        'status' => 'statuses',
        'testis' => 'testes',
        'toe' => 'toes',
        'tooth' => 'teeth',
        'trilby' => 'trilbys',
        'turf' => 'turfs',
        'vertex' => 'vertices',
    ];

    /**
     * @var array<string, string>
     */
    protected array $plural = [
        '/([ml])ouse\z/i' => '$1ice',
        '/([^aeiouy]|qu)y\z/i' => '$1ies',
        '/(?:([^f])fe|([lre])f)\z/i' => '$1$2ves',
        '/(?<!s)sis\z/i' => 'ses',
        '/([ti])um\z/i' => '$1a',
        '/(?<!u)man\z/i' => 'men',
        '/(buffal|tomat)o\z/i' => '$1oes',
        '/(alumn|bacill|cact|foc|fung|nucle|radi|stimul|syllab|termin)us\z/i' => '$1i',
        '/us\z/i' => 'uses',
        '/(x|ch|ss|sh)\z/i' => '$1es',
        '/s\z/' => 's',
        '/\A\z/' => '',
        '/\z/' => 's',
    ];

    /**
     * @var array<string, string>
     */
    protected array $singular = [
        '/([ml])ice\z/i' => '$1ouse',
        '/([^aeiouy]|qu)ies\z/i' => '$1y',
        '/([lre])ves\z/i' => '$1f',
        '/([^f])ves\z/i' => '$1fe',
        '/(?<!s)ses\z/i' => 'sis',
        '/([ti])a\z/i' => '$1um',
        '/(?<!u)men\z/i' => 'man',
        '/oes\z/i' => 'o',
        '/(alumn|bacill|cact|foc|fung|nucle|radi|stimul|syllab|termin|viri?)i\z/i' => '$1us',
        '/(x|ch|ss|sh)es\z/i' => '$1',
        '/s\z/i' => '',
    ];

    /**
     * @var string[]
     */
    protected array $uncountable = [
        '.*data',
        '.*deer',
        '.*fish',
        '.*measles',
        '.*ois',
        '.*pox',
        '.*sheep',
        '.*[nrlm]ese',
        '.*?media',

        'aircraft',
        'bison',
        'chassis',
        'clippers',
        'debris',
        'diabetes',
        'equipment',
        'feedback',
        'gallows',
        'graffiti',
        'headquarters',
        'information',
        'innings',
        'moose',
        'nexus',
        'news',
        'pokemon',
        'proceedings',
        'research',
        'salmon',
        'sea[- ]bass',
        'series',
        'shrimp',
        'spacecraft',
        'species',
        'stadia',
        'weather',
    ];

    /**
     * Converts a delimited string into CamelCase.
     *
     * @param string $string The input string.
     * @param string $delimiter The delimiter.
     * @return string The CamelCase string.
     */
    public function camelize(string $string, string $delimiter = '_'): string
    {
        return $this->cache(__FUNCTION__.$delimiter, $string, function(string $string) use ($delimiter): string {
            return str_replace(' ', '', $this->humanize($string, $delimiter));
        });
    }

    /**
     * Converts a table_name to a singular ClassName.
     *
     * @param string $tableName The table name.
     * @return string The classified string.
     */
    public function classify(string $tableName): string
    {
        return $this->cache(
            __FUNCTION__,
            $tableName,
            fn(string $tableName): string => $this->singularize($tableName) |> $this->camelize(...)
        );
    }

    /**
     * Converts a string into kebab-case.
     *
     * @param string $string The input string.
     * @return string The kebab-case string.
     */
    public function dasherize(string $string): string
    {
        return $this->delimit(str_replace('_', '-', $string), '-');
    }

    /**
     * Converts a string into human readable form.
     *
     * @param string $string The input string.
     * @param string $delimiter The delimiter.
     * @return string The human readable form string.
     */
    public function humanize(string $string, string $delimiter = '_'): string
    {
        return $this->cache(
            __FUNCTION__.$delimiter,
            $string,
            static fn(string $string): string => str_replace($delimiter, ' ', $string) |> ucwords(...)
        );
    }

    /**
     * Returns the plural form of a word.
     *
     * @param string $string The input string.
     * @return string The pluralized word.
     */
    public function pluralize(string $string): string
    {
        return $this->cache(__FUNCTION__, $string, function(string $string): string {
            if ($this->isUncountable($string)) {
                return $string;
            }

            $keys = array_map(preg_quote(?, '/'), array_keys($this->irregular));

            if (preg_match('/('.implode('|', $keys).')\z/i', $string, $match)) {
                $key = $match[1];
                $value = $this->irregular[strtolower($key)];

                return (string) preg_replace(
                    '/'.preg_quote($key, '/').'\z/i',
                    $value,
                    $string
                );
            }

            foreach ($this->plural as $pattern => $replace) {
                if (!preg_match($pattern, $string)) {
                    continue;
                }

                return (string) preg_replace($pattern, $replace, $string);
            }

            return $string;
        });
    }

    /**
     * Adds or overrides inflection rules.
     *
     * The $type argument must be one of:
     *   - 'irregular' (array<string, string>) lowercase word → lowercase plural
     *   - 'plural' (array<string, string>) regex pattern → replacement
     *   - 'singular' (array<string, string>) regex pattern → replacement
     *   - 'uncountable' (string[]) lowercase literal or regex pattern
     *
     * All rule keys and values must be lowercase unless the key is a regex.
     * Regex rules may use any casing and should define their own modifiers.
     *
     * Behaviour:
     *   - 'irregular', 'plural', and 'singular' rules overwrite existing rules.
     *   - 'uncountable' rules are prepended.
     *   - All internal caches are cleared after updating rules.
     *
     * @param string $type The rule group to modify (lowercase).
     * @param string[] $rules The rules to add or override.
     * @return static The Inflector instance.
     */
    public function rules(string $type, array $rules): static
    {
        if ($type === 'uncountable') {
            /** @var string[] $rules */
            $this->uncountable = array_merge($rules, $this->uncountable);
        } else if (in_array($type, ['irregular', 'plural', 'singular'], true)) {
            /** @var array<string, string> $rules */
            $this->$type = array_replace($this->$type, $rules);
        }

        $this->cache = [];

        return $this;
    }

    /**
     * Returns the singular form of a word.
     *
     * @param string $string The input string.
     * @return string The singularized word.
     */
    public function singularize(string $string): string
    {
        return $this->cache(__FUNCTION__, $string, function(string $string): string {
            if ($this->isUncountable($string)) {
                return $string;
            }

            $values = array_map(preg_quote(?, '/'), $this->irregular);

            if (preg_match('/('.implode('|', $values).')\z/i', $string, $match)) {
                $value = $match[1];
                $key = (string) array_search(strtolower($value), $this->irregular, true);

                return (string) preg_replace('/'.$match[1].'\z/i', $key, $string);
            }

            foreach ($this->singular as $pattern => $replace) {
                if (!preg_match($pattern, $string)) {
                    continue;
                }

                return (string) preg_replace($pattern, $replace, $string);
            }

            return $string;
        });
    }

    /**
     * Converts a singular ClassName to a pluralized table_name.
     *
     * @param string $className The class name.
     * @return string The tableized string.
     */
    public function tableize(string $className): string
    {
        return $this->cache(
            __FUNCTION__,
            $className,
            fn(string $string): string => $this->underscore($string) |> $this->pluralize(...)
        );
    }

    /**
     * Converts a string into snake_case.
     *
     * @param string $string The input string.
     * @return string The string.
     */
    public function underscore(string $string): string
    {
        return $this->delimit(str_replace('-', '_', $string), '_');
    }

    /**
     * Converts a string into a lower camelCase variable name.
     *
     * @param string $string The input string.
     * @return string The string.
     */
    public function variable(string $string): string
    {
        return $this->cache(
            __FUNCTION__,
            $string,
            fn(string $string): string => $this->underscore($string) |> $this->camelize(...) |> lcfirst(...)
        );
    }

    /**
     * Retrieves a value from the cache, or generates it from a callback if it doesn't exist.
     *
     * @param string $type The cache type.
     * @param string $value The cache value.
     * @param Closure(string): string $callback The callback.
     * @return string The generated value.
     */
    protected function cache(string $type, string $value, Closure $callback): string
    {
        $this->cache[$type] ??= [];

        return $this->cache[$type][$value] ??= $callback($value);
    }

    /**
     * Delimits a camelCase string.
     *
     * @param string $string The input string.
     * @param string $delimiter The delimiter.
     * @return string The delimited string.
     */
    protected function delimit(string $string, string $delimiter = '_'): string
    {
        return $this->cache(
            __FUNCTION__.$delimiter,
            $string,
            static fn(string $string): string => (string) preg_replace('/(?<=\\w)([A-Z])/', $delimiter.'\\1', $string) |> strtolower(...)
        );
    }

    /**
     * Checks whether a word is uncountable.
     *
     * @param string $string The input string.
     * @return bool Whether the word is uncountable.
     */
    protected function isUncountable(string $string): bool
    {
        return preg_match('/\A('.implode('|', $this->uncountable).')\z/i', $string) === 1;
    }
}
