<?php
declare(strict_types=1);

namespace Fyre\Console;

use Fyre\Core\Traits\DebugTrait;
use Fyre\Core\Traits\MacroTrait;
use Fyre\Core\Traits\StaticMacroTrait;
use NumberFormatter;

use function array_is_list;
use function array_keys;
use function array_unshift;
use function clamp;
use function count;
use function exec;
use function fgets;
use function fopen;
use function fwrite;
use function implode;
use function is_resource;
use function max;
use function mb_strwidth;
use function preg_replace;
use function round;
use function rtrim;
use function str_pad;
use function str_repeat;
use function strcasecmp;
use function strlen;
use function wordwrap;

use const PHP_EOL;
use const PHP_SAPI;
use const STDERR;
use const STDIN;
use const STDOUT;

/**
 * Provides a lightweight console I/O facade.
 */
class Console
{
    use DebugTrait;
    use MacroTrait;
    use StaticMacroTrait;

    public const BLACK = 30;

    public const BLUE = 34;

    public const BOLD = 1;

    public const CYAN = 36;

    public const DARKGRAY = 90;

    public const DIM = 2;

    public const FLASH = 5;

    public const GRAY = 37;

    public const GREEN = 32;

    public const ITALIC = 3;

    public const PURPLE = 35;

    public const RED = 31;

    public const UNDERLINE = 4;

    public const WHITE = 97;

    public const YELLOW = 33;

    protected const TOTAL_STEPS = 10;

    protected static NumberFormatter $percentFormatter;

    protected int|null $lastStep = null;

    /**
     * Returns the terminal height in characters.
     *
     * Note: If terminal size cannot be resolved, this falls back to `24`.
     *
     * @return int The terminal height.
     */
    public static function getHeight(): int
    {
        $height = (int) exec('tput lines');

        return $height > 0 ? $height : 24;
    }

    /**
     * Returns the terminal width in characters.
     *
     * Note: If terminal size cannot be resolved, this falls back to `80`.
     *
     * @return int The terminal width.
     */
    public static function getWidth(): int
    {
        $width = (int) exec('tput cols');

        return $width > 0 ? $width : 80;
    }

    /**
     * Styles a string for terminal output.
     *
     * @param string $text The text.
     * @param int|null $color The text color.
     * @param int|null $background The text background.
     * @param int $style The text style.
     * @return string The styled text.
     */
    public static function style(string $text, int|null $color = null, int|null $background = null, int $style = 0): string
    {
        if (!$text || (!$color && !$background && !$style)) {
            return $text;
        }

        $color ??= static::WHITE;

        $result = "\033[";
        $result .= $style;
        $result .= ';';
        $result .= $color;

        if ($background !== null) {
            $result .= ';';
            $result .= $background + 10;
        }

        $result .= 'm';
        $result .= $text;
        $result .= "\033[0m";

        return $result;
    }

    /**
     * Wraps text for terminal output.
     *
     * @param string $text The text.
     * @param int|null $maxWidth The maximum width.
     * @return string The wrapped text.
     */
    public static function wrap(string $text, int|null $maxWidth = null): string
    {
        $terminalWidth = static::getWidth();

        $maxWidth = clamp($maxWidth ?? $terminalWidth, 1, $terminalWidth);

        return wordwrap($text, $maxWidth, PHP_EOL);
    }

    /**
     * Constructs a Console.
     *
     * Note: When running under `cli`, the standard streams are used. Otherwise, output is written to
     * `php://output` and the error stream defaults to the output stream.
     *
     * @param resource|null $input The input stream.
     * @param resource|null $output The output stream.
     * @param resource|null $error The error stream.
     */
    public function __construct(
        protected $input = null,
        protected $output = null,
        protected $error = null
    ) {
        if (PHP_SAPI === 'cli') {
            $this->input ??= STDIN;
            $this->output ??= STDOUT;
            $this->error ??= STDERR;
        } else {
            $this->output ??= fopen('php://output', 'w') ?: null;
            $this->error ??= $this->output;
        }
    }

    /**
     * Prompts the user to choose from available options.
     *
     * Note: When `$options` is an associative array, the keys are treated as choices and the values are
     * displayed as descriptions.
     *
     * @param string $text The prompt text.
     * @param array<int|string> $options The options.
     * @param int|string|null $default The default option.
     * @return int|string|null The selected option.
     */
    public function choice(string $text, array $options, int|string|null $default = null): int|string|null
    {
        $this->write($text, static::YELLOW);

        $prefix = '';
        if (!array_is_list($options)) {
            $optionKeys = array_keys($options);

            $maxLength = 0;
            foreach ($optionKeys as $option) {
                $maxLength = max($maxLength, strlen((string) $option));
            }

            foreach ($options as $option => $description) {
                $key = str_pad('  ['.$option.']', $maxLength + 6)
                    |> static::style(?, static::CYAN);
                $value = static::style((string) $description, style: static::DIM);

                $this->write($key.$value);
            }

            $prefix = static::style('Choice', static::YELLOW);
        } else {
            $optionKeys = $options;
        }

        $optionList = [];
        foreach ($optionKeys as $option) {
            if ($option === $default) {
                $style = static::BOLD;
            } else {
                $style = static::DIM;
            }

            $optionList[] = static::style((string) $option, static::CYAN, style: $style);
        }

        $this->write($prefix.' ('.implode('/', $optionList).')');

        $choice = $this->input() ?: (string) $default;

        foreach ($optionKeys as $option) {
            if (strcasecmp((string) $option, $choice) === 0) {
                return $option;
            }
        }

        return $default;
    }

    /**
     * Outputs comment text.
     *
     * @param string $text The text.
     * @param int|null $color The text color.
     * @param int|null $background The text background.
     * @param int $style The text style.
     */
    public function comment(string $text, int|null $color = null, int|null $background = null, int $style = self::DIM): void
    {
        $this->write($text, $color, $background, $style);
    }

    /**
     * Prompts the user to confirm (y/n).
     *
     * @param string $text The prompt text.
     * @param bool $default The default option.
     * @return bool Whether the user confirmed the prompt.
     */
    public function confirm(string $text, bool $default = true): bool
    {
        $choice = $this->choice($text, ['y', 'n'], $default ? 'y' : 'n');

        return $choice === 'y';
    }

    /**
     * Outputs text to STDERR.
     *
     * @param string $text The text.
     * @param int $color The text color.
     * @param int|null $background The text background.
     * @param int $style The text style.
     */
    public function error(string $text, int $color = self::RED, int|null $background = null, int $style = 0): void
    {
        if (!is_resource($this->error)) {
            return;
        }

        $text = static::style($text, $color, $background, $style);

        fwrite($this->error, $text.PHP_EOL);
    }

    /**
     * Outputs info text.
     *
     * @param string $text The text.
     * @param int $color The text color.
     * @param int|null $background The text background.
     * @param int $style The text style.
     */
    public function info(string $text, int $color = self::BLUE, int|null $background = null, int $style = 0): void
    {
        $this->write($text, $color, $background, $style);
    }

    /**
     * Reads a line of input.
     *
     * Note: When no input stream is available, an empty string is returned.
     *
     * @return string The input text.
     */
    public function input(): string
    {
        if (!is_resource($this->input)) {
            return '';
        }

        return rtrim((string) fgets($this->input), "\r\n");
    }

    /**
     * Outputs a progress indicator.
     *
     * Note: When `$step` is null, the progress indicator is cleared.
     *
     * @param int|null $step The step.
     * @param int $totalSteps The total steps.
     */
    public function progress(int|null $step = null, int $totalSteps = 10): void
    {
        if (!is_resource($this->output)) {
            return;
        }

        if ($step === null) {
            $this->lastStep = null;

            fwrite($this->output, "\033[1A\033[K");
            fwrite($this->output, "\007");

            return;
        }

        $step = max(1, $step);
        $totalSteps = max(1, $totalSteps);

        if ($this->lastStep && $this->lastStep <= $step) {
            fwrite($this->output, "\r\033[1A\r\033[K\r");
        }

        $this->lastStep = $step;

        $percent = clamp($step / $totalSteps, 0, 1);

        $barStep = (int) round($percent * static::TOTAL_STEPS);

        $progressString = str_repeat('#', $barStep).
            str_repeat('.', static::TOTAL_STEPS - $barStep);

        $percentString = static::percentFormatter()->format($percent);

        $this->write('['.static::style($progressString, static::GREEN).'] '.$percentString);
    }

    /**
     * Prompts the user for input.
     *
     * @param string $text The prompt text.
     * @return string The input text.
     */
    public function prompt(string $text): string
    {
        $this->write($text, static::YELLOW);

        return $this->input();
    }

    /**
     * Outputs success text.
     *
     * @param string $text The text.
     * @param int $color The text color.
     * @param int|null $background The text background.
     * @param int $style The text style.
     */
    public function success(string $text, int $color = self::GREEN, int|null $background = null, int $style = 0): void
    {
        $this->write($text, $color, $background, $style);
    }

    /**
     * Outputs a table.
     *
     * Each row of the table must be an array of column values. All rows must
     * contain the same number of columns. When a header row is provided, it will be
     * displayed as the first row and separated from the rest of the table by a
     * horizontal border.
     *
     * ANSI styles in cell contents are preserved when printed, but do not affect
     * column width calculation.
     *
     * @param mixed[][] $data The table rows.
     * @param string[] $header The table header columns.
     */
    public function table(array $data, array $header = []): void
    {
        if (!is_resource($this->output)) {
            return;
        }

        if ($header !== []) {
            array_unshift($data, $header);
        }

        $maxLengths = [];

        foreach ($data as $row) {
            foreach ($row as $i => $value) {
                $maxLengths[$i] ??= 0;
                $maxLengths[$i] = max($maxLengths[$i], static::strlen((string) $value));
            }
        }

        $border = '+';
        foreach ($maxLengths as $length) {
            $border .= str_repeat('-', $length + 2).'+';
        }
        $border .= PHP_EOL;

        foreach ($data as $i => $row) {
            foreach ($row as $j => $value) {
                $diff = $maxLengths[$j] - static::strlen((string) $value);
                $data[$i][$j] .= str_repeat(' ', $diff);
            }
        }

        $rowCount = count($data);

        $table = '';

        foreach ($data as $i => $row) {
            if ($i === 0) {
                $table .= $border;
            }

            $table .= '| '.implode(' | ', $row).' |'.PHP_EOL;

            if (($i === 0 && $header !== []) || $i === $rowCount - 1) {
                $table .= $border;
            }
        }

        fwrite($this->output, $table);
    }

    /**
     * Outputs warning text.
     *
     * @param string $text The text.
     * @param int $color The text color.
     * @param int|null $background The text background.
     * @param int $style The text style.
     */
    public function warning(string $text, int $color = self::YELLOW, int|null $background = null, int $style = 0): void
    {
        $this->write($text, $color, $background, $style);
    }

    /**
     * Outputs text to STDOUT.
     *
     * @param string $text The text.
     * @param int|null $color The text color.
     * @param int|null $background The text background.
     * @param int $style The text style.
     */
    public function write(string $text, int|null $color = null, int|null $background = null, int $style = 0): void
    {
        if (!is_resource($this->output)) {
            return;
        }

        $text = static::style($text, $color, $background, $style);

        fwrite($this->output, $text.PHP_EOL);
    }

    /**
     * Creates a percent formatter.
     *
     * @return NumberFormatter The percent formatter.
     */
    protected static function percentFormatter(): NumberFormatter
    {
        return static::$percentFormatter ??= new NumberFormatter('en_US', NumberFormatter::PERCENT);
    }

    /**
     * Returns the real length of a string.
     *
     * @param string $string The string.
     * @return int The length.
     */
    protected static function strlen(string $string): int
    {
        return ((string) preg_replace('/\\033\[[\d;]+?m/', '', $string)) |> mb_strwidth(...);
    }
}
