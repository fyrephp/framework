<?php
declare(strict_types=1);

namespace Fyre\TestSuite\Traits;

use Fyre\Console\Command;
use Fyre\Console\CommandRunner;
use Fyre\Console\Console;
use Fyre\TestSuite\Constraint\Console\ContentsContains;
use Fyre\TestSuite\Constraint\Console\ContentsContainsRow;
use Fyre\TestSuite\Constraint\Console\ContentsEmpty;
use Fyre\TestSuite\Constraint\Console\ContentsNotContains;
use Fyre\TestSuite\Constraint\Console\ContentsRegExp;
use Fyre\TestSuite\Constraint\Console\ExitCode;
use Fyre\TestSuite\TestCase;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

use function array_merge;
use function explode;
use function fclose;
use function fopen;
use function ftruncate;
use function fwrite;
use function is_resource;
use function rewind;
use function rtrim;
use function str_getcsv;
use function stream_get_contents;

use const PHP_EOL;

/**
 * Test case helpers for console assertions.
 *
 * Provides helpers for running console commands via {@see CommandRunner} and asserting on
 * captured stdout/stderr and exit codes.
 *
 * @phpstan-require-extends TestCase
 */
trait ConsoleTestTrait
{
    /**
     * @var resource
     */
    protected $error;

    protected int|null $exitCode = null;

    /**
     * @var resource
     */
    protected $input;

    protected Console $originalConsole;

    /**
     * @var resource
     */
    protected $output;

    protected CommandRunner $runner;

    /**
     * Assert that a line in stderr contains a value.
     *
     * @param string $value The expected value.
     * @param string $message The message to display on failure.
     */
    public function assertErrorContains(string $value, string $message = ''): void
    {
        $this->assertThat(
            static::getLines($this->error),
            new ContentsContains($value, 'stderr'),
            $message
        );
    }

    /**
     * Assert that no lines were sent to stderr.
     *
     * @param string $message The message to display on failure.
     */
    public function assertErrorEmpty(string $message = ''): void
    {
        $this->assertThat(
            static::getLines($this->error),
            new ContentsEmpty('stderr'),
            $message
        );
    }

    /**
     * Assert that a line in stderr matches a regex pattern.
     *
     * @param string $pattern The expected pattern.
     * @param string $message The message to display on failure.
     */
    public function assertErrorRegExp(string $pattern, string $message = ''): void
    {
        $this->assertThat(
            static::getLines($this->error),
            new ContentsRegExp($pattern, 'stderr'),
            $message
        );
    }

    /**
     * Assert that the command exit code matches the expected code.
     *
     * @param int $code The expected exit code.
     * @param string $message The message to display on failure.
     */
    public function assertExitCode(int $code, string $message = ''): void
    {
        $this->assertThat(
            $this->exitCode,
            new ExitCode($code),
            $message
        );
    }

    /**
     * Assert that the command exit code matches the error code.
     *
     * @param string $message The message to display on failure.
     */
    public function assertExitError(string $message = ''): void
    {
        $this->assertThat(
            $this->exitCode,
            new ExitCode(Command::CODE_ERROR),
            $message
        );
    }

    /**
     * Assert that the command exit code matches the success code.
     *
     * @param string $message The message to display on failure.
     */
    public function assertExitSuccess(string $message = ''): void
    {
        $this->assertThat(
            $this->exitCode,
            new ExitCode(Command::CODE_SUCCESS),
            $message
        );
    }

    /**
     * Assert that a line in stdout contains a value.
     *
     * @param string $value The expected value.
     * @param string $message The message to display on failure.
     */
    public function assertOutputContains(string $value, string $message = ''): void
    {
        $this->assertThat(
            static::getLines($this->output),
            new ContentsContains($value, 'stdout'),
            $message
        );
    }

    /**
     * Assert that a line in stdout contains a table row.
     *
     * @param array $value The expected value.
     * @param string $message The message to display on failure.
     */
    public function assertOutputContainsRow(array $value, string $message = ''): void
    {
        $this->assertThat(
            static::getLines($this->output),
            new ContentsContainsRow($value, 'stdout'),
            $message
        );
    }

    /**
     * Assert that no lines were sent to stdout.
     *
     * @param string $message The message to display on failure.
     */
    public function assertOutputEmpty(string $message = ''): void
    {
        $this->assertThat(
            static::getLines($this->output),
            new ContentsEmpty('stdout'),
            $message
        );
    }

    /**
     * Assert that no lines in stdout contain a value.
     *
     * @param string $value The expected value.
     * @param string $message The message to display on failure.
     */
    public function assertOutputNotContains(string $value, string $message = ''): void
    {
        $this->assertThat(
            static::getLines($this->output),
            new ContentsNotContains($value, 'stdout'),
            $message
        );
    }

    /**
     * Assert that a line in stdout matches a regex pattern.
     *
     * @param string $pattern The expected pattern.
     * @param string $message The message to display on failure.
     */
    public function assertOutputRegExp(string $pattern, string $message = ''): void
    {
        $this->assertThat(
            static::getLines($this->output),
            new ContentsRegExp($pattern, 'stdout'),
            $message
        );
    }

    /**
     * Run a command.
     *
     * @param string $command The command.
     * @param string[] $input The input.
     */
    public function exec(string $command, array $input = []): void
    {
        ftruncate($this->input, 0);
        ftruncate($this->output, 0);
        ftruncate($this->error, 0);

        rewind($this->input);
        rewind($this->output);
        rewind($this->error);

        foreach ($input as $line) {
            fwrite($this->input, $line.PHP_EOL);
        }

        rewind($this->input);

        $argv = str_getcsv($command, ' ', '"', '\\');

        $this->exitCode = array_merge([''], $argv) |> $this->runner->handle(...);
    }

    /**
     * Set up the test case.
     */
    #[Before(-1)]
    protected function setUpConsole(): void
    {
        $this->originalConsole = $this->app->use(Console::class);

        $this->input = fopen('php://memory', 'r+b');
        $this->output = fopen('php://memory', 'r+b');
        $this->error = fopen('php://memory', 'r+b');

        $console = new Console($this->input, $this->output, $this->error);

        $this->app->instance(Console::class, $console);

        $this->runner = $this->app->use(CommandRunner::class);
    }

    /**
     * Tear down the test case.
     */
    #[After]
    protected function tearDownConsole(): void
    {
        $this->app->instance(Console::class, $this->originalConsole);

        fclose($this->input);
        fclose($this->output);
        fclose($this->error);
    }

    /**
     * Returns the lines from a stream.
     *
     * Note: When the stream is empty, this returns an empty array.
     *
     * @param resource $stream The stream.
     * @return array The lines.
     */
    protected static function getLines(mixed $stream): array
    {
        if (!is_resource($stream)) {
            return [];
        }

        rewind($stream);

        $lines = stream_get_contents($stream) |> rtrim(?, PHP_EOL);

        if ($lines === '') {
            return [];
        }

        return explode(PHP_EOL, $lines);
    }
}
