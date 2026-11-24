<?php
declare(strict_types=1);

namespace Fyre\Core;

use Fyre\Core\Traits\DebugTrait;
use Fyre\Core\Traits\MacroTrait;
use Fyre\Utility\Arr;
use Fyre\Utility\Path;
use MessageFormatter;

use function array_filter;
use function array_pop;
use function array_replace_recursive;
use function array_splice;
use function array_unshift;
use function explode;
use function file_exists;
use function implode;
use function in_array;
use function is_string;
use function locale_canonicalize;
use function locale_get_default;
use function strtok;
use function strtolower;

/**
 * Manages language translations and locale-specific messages.
 */
class Lang
{
    use DebugTrait;
    use MacroTrait;

    protected string|null $defaultLocale = null;

    /**
     * @var array<string, mixed>
     */
    protected array $lang = [];

    protected string|null $locale = null;

    /**
     * @var string[]
     */
    protected array $paths = [];

    /**
     * Constructs a Lang.
     *
     * @param Config $config The Config.
     */
    public function __construct(Config $config)
    {
        $this->defaultLocale = $config->get('App.defaultLocale');
    }

    /**
     * Adds a language path.
     *
     * Paths are searched and merged in the order returned by {@see Lang::getPaths()}.
     * When multiple paths define the same key, later paths win (due to merge order).
     *
     * @param string $path The path to add.
     * @param bool $prepend Whether to prepend the path.
     * @return static The Lang instance.
     */
    public function addPath(string $path, bool $prepend = false): static
    {
        $path = Path::resolve($path);

        if (!in_array($path, $this->paths, true)) {
            if ($prepend) {
                array_unshift($this->paths, $path);
            } else {
                $this->paths[] = $path;
            }
        }

        return $this;
    }

    /**
     * Clears the language data.
     */
    public function clear(): void
    {
        $this->paths = [];
        $this->lang = [];
    }

    /**
     * Returns a language value using "dot" notation.
     *
     * The first segment of the key is treated as the language file name. For example, the key
     * `errors.required` loads `errors.php`.
     *
     * If the key contains no dot, the full file array is returned (if available).
     *
     * @param string $key The language key.
     * @param array<array-key, mixed> $data The data used for formatting.
     * @return array<string, mixed>|string|null The formatted language string.
     */
    public function get(string $key, array $data = []): array|string|null
    {
        $file = (string) strtok($key, '.');

        $this->lang[$file] ??= $this->load($file);

        $line = Arr::getDot($this->lang, $key);

        if ($data === [] || !is_string($line) || $line === '') {
            return $line;
        }

        return (string) MessageFormatter::formatMessage($this->getLocale(), $line, $data);
    }

    /**
     * Returns the default locale.
     *
     * @return string The default locale.
     */
    public function getDefaultLocale(): string
    {
        return $this->defaultLocale ??= locale_get_default();
    }

    /**
     * Returns the current locale.
     *
     * @return string The current locale.
     */
    public function getLocale(): string
    {
        return $this->locale ?? $this->getDefaultLocale();
    }

    /**
     * Returns the paths.
     *
     * @return string[] The paths.
     */
    public function getPaths(): array
    {
        return $this->paths;
    }

    /**
     * Removes a path.
     *
     * @param string $path The path to remove.
     * @return static The Lang instance.
     */
    public function removePath(string $path): static
    {
        $path = Path::resolve($path);

        foreach ($this->paths as $i => $otherPath) {
            if ($otherPath !== $path) {
                continue;
            }

            array_splice($this->paths, $i, 1);
            break;
        }

        return $this;
    }

    /**
     * Sets the default locale.
     *
     * @param string|null $locale The locale.
     * @return static The Lang instance.
     */
    public function setDefaultLocale(string|null $locale = null): static
    {
        $this->defaultLocale = $locale;

        $this->lang = [];

        return $this;
    }

    /**
     * Sets the current locale.
     *
     * @param string|null $locale The locale.
     * @return static The Lang instance.
     */
    public function setLocale(string|null $locale = null): static
    {
        $this->locale = $locale;

        $this->lang = [];

        return $this;
    }

    /**
     * Returns a list of locales.
     *
     * Locales are returned in increasing precedence (fallbacks first).
     *
     * Locale values are canonicalized (e.g. `en-US` becomes `en_US`) and then normalized to
     * lowercase for language folder lookups (e.g. `en_US` becomes `en_us`).
     *
     * Within each locale, variants are ordered from least-specific to most-specific (e.g. `en`
     * then `en_us`). Locales derived from the default locale (if different) are included as
     * fallbacks and will appear before locales derived from the current locale.
     *
     * @return string[] The locales.
     */
    protected function getLocales(): array
    {
        $testLocales = array_filter([
            $this->getLocale() |> locale_canonicalize(...) ?? '',
            $this->getDefaultLocale() |> locale_canonicalize(...) ?? '',
        ]);

        $locales = [];

        foreach ($testLocales as $locale) {
            $localeParts = strtolower($locale) |> explode('_', ?);
            while ($localeParts !== []) {
                $newLocale = implode('_', $localeParts);

                if (in_array($newLocale, $locales, true)) {
                    break;
                }

                array_unshift($locales, $newLocale);
                array_pop($localeParts);
            }
        }

        return $locales;
    }

    /**
     * Loads a language file.
     *
     * Files are merged in order:
     * - least-specific locale to most-specific locale (more specific overrides less specific)
     * - earlier paths to later paths (later overrides earlier)
     *
     * @param string $file The file.
     * @return array<string, mixed> The language values.
     */
    protected function load(string $file): array
    {
        $file .= '.php';
        $locales = $this->getLocales();

        $lang = [];
        foreach ($locales as $locale) {
            foreach ($this->paths as $path) {
                $filePath = Path::join($path, $locale, $file);

                if (!file_exists($filePath)) {
                    continue;
                }

                $data = require $filePath;
                $lang = array_replace_recursive($lang, $data);
            }
        }

        return $lang;
    }
}
