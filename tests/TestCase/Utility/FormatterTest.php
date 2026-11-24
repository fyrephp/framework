<?php
declare(strict_types=1);

namespace Tests\TestCase\Utility;

use Fyre\Core\Config;
use Fyre\Core\Container;
use Fyre\Core\Traits\DebugTrait;
use Fyre\Core\Traits\MacroTrait;
use Fyre\DB\TypeParser;
use Fyre\Utility\DateTime\Date;
use Fyre\Utility\DateTime\DateTime;
use Fyre\Utility\DateTime\Time;
use Fyre\Utility\Formatter;
use InvalidArgumentException;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function class_uses;
use function str_replace;

final class FormatterTest extends TestCase
{
    protected Formatter $formatter;

    /**
     * @return array<string, array{float|int|string, string}>
     */
    public static function currencyProvider(): array
    {
        return [
            'integer' => [123, '$123.00'],
            'float' => [123.456, '$123.46'],
            'string' => ['123.456', '$123.46'],
        ];
    }

    /**
     * @return array<string, array{float|int|string, string}>
     */
    public static function numberProvider(): array
    {
        return [
            'integer' => [1234, '1,234'],
            'float' => [1234.567, '1,234.567'],
            'string' => ['1234.567', '1,234.567'],
        ];
    }

    /**
     * @return array<string, array{float|int|string, string}>
     */
    public static function percentProvider(): array
    {
        return [
            'integer' => [1, '100%'],
            'float' => [0.123, '12%'],
            'string' => ['0.123', '12%'],
        ];
    }

    #[DataProvider('currencyProvider')]
    public function testCurrency(float|int|string $value, string $expected): void
    {
        $this->assertSame(
            $expected,
            $this->formatter->currency($value)
        );
    }

    public function testCurrencyOptions(): void
    {
        $this->assertSame(
            '£123.00',
            $this->formatter->currency(123, 'gbp', 'en-GB')
        );
    }

    public function testDate(): void
    {
        $date = new DateTime('2022-01-01');

        $this->assertSame(
            '01/01/2022',
            $this->formatter->date($date)
        );
    }

    public function testDateDate(): void
    {
        $date = Date::createFromArray([2022, 1, 1]);

        $this->assertSame(
            '01/01/2022',
            $this->formatter->date($date)
        );
    }

    public function testDateDateLocale(): void
    {
        $date = Date::createFromArray([2022, 1, 1]);

        $this->assertSame(
            '٢٠٢٢-٠١-٠١',
            $this->formatter->date($date, 'yyyy-MM-dd', locale: 'ar-EG-u-nu-arab')
        );
    }

    public function testDateDateTimeZone(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Time zone overrides are only supported for DateTime values.');

        $date = Date::createFromArray([2022, 1, 1]);

        $this->formatter->date($date, 'yyyy-MM-dd', 'America/New_York');
    }

    public function testDateOptions(): void
    {
        $date = new DateTime('2022-01-01');

        $this->assertSame(
            '٢٠٢٢-٠١-٠١',
            $this->formatter->date($date, 'yyyy-MM-dd', locale: 'ar-EG-u-nu-arab')
        );
    }

    public function testDateTime(): void
    {
        $date = new DateTime('2022-01-01 11:59:59');

        $formatted = str_replace(
            ["\u{00A0}", "\u{202F}"],
            ' ',
            $this->formatter->datetime($date)
        );

        $this->assertSame(
            '01/01/2022, 11:59 AM',
            $formatted
        );
    }

    public function testDateTimeOptions(): void
    {
        $date = new DateTime('2022-01-01 11:59:59');

        $this->assertSame(
            '٢٠٢٢-٠١-٠١ ٠٦:٥٩:٥٩',
            $this->formatter->datetime($date, 'yyyy-MM-dd HH:mm:ss', 'America/New_York', 'ar-EG-u-nu-arab')
        );
    }

    public function testDebug(): void
    {
        $this->assertContains(
            DebugTrait::class,
            class_uses(Formatter::class)
        );
    }

    public function testList(): void
    {
        $data = ['Test 1', 'Test 2', 'Test 3'];

        $this->assertSame(
            'Test 1, Test 2, and Test 3',
            $this->formatter->list($data)
        );
    }

    public function testListOptions(): void
    {
        $data = ['Test 1', 'Test 2', 'Test 3'];

        $this->assertSame(
            'Test 1, Test 2 или Test 3',
            $this->formatter->list($data, 'or', locale: 'ru-RU')
        );
    }

    public function testMacro(): void
    {
        $this->assertContains(
            MacroTrait::class,
            class_uses(Formatter::class)
        );
    }

    #[DataProvider('numberProvider')]
    public function testNumber(float|int|string $value, string $expected): void
    {
        $this->assertSame(
            $expected,
            $this->formatter->number($value)
        );
    }

    #[DataProvider('percentProvider')]
    public function testPercent(float|int|string $value, string $expected): void
    {
        $this->assertSame(
            $expected,
            $this->formatter->percent($value)
        );
    }

    public function testTime(): void
    {
        $date = new DateTime('2022-01-01 11:59:59');

        $formatted = str_replace(
            ["\u{00A0}", "\u{202F}"],
            ' ',
            $this->formatter->time($date)
        );

        $this->assertSame(
            '11:59 AM',
            $formatted
        );
    }

    public function testTimeOptions(): void
    {
        $date = new DateTime('2022-01-01 11:59:59');

        $this->assertSame(
            '٠٦:٥٩:٥٩',
            $this->formatter->time($date, 'HH:mm:ss', 'America/New_York', 'ar-EG-u-nu-arab')
        );
    }

    public function testTimeTime(): void
    {
        $time = Time::createFromArray([11, 59, 59]);

        $formatted = str_replace(
            ["\u{00A0}", "\u{202F}"],
            ' ',
            $this->formatter->time($time)
        );

        $this->assertSame(
            '11:59 AM',
            $formatted
        );
    }

    public function testTimeTimeLocale(): void
    {
        $time = Time::createFromArray([11, 59, 59]);

        $this->assertSame(
            '١١:٥٩:٥٩',
            $this->formatter->time($time, 'HH:mm:ss', locale: 'ar-EG-u-nu-arab')
        );
    }

    public function testTimeTimeZone(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Time zone overrides are only supported for DateTime values.');

        $time = Time::createFromArray([0, 30]);

        $this->formatter->time($time, 'HH:mm:ss', 'America/New_York');
    }

    #[Override]
    protected function setUp(): void
    {
        $container = new Container();
        $container->singleton(TypeParser::class);
        $container->singleton(Config::class);
        $container->use(Config::class)->set('App', [
            'defaultLocale' => 'en-US',
            'defaultCurrency' => 'USD',
        ]);

        $this->formatter = $container->build(Formatter::class);

        DateTime::setDefaultLocale('en');
        DateTime::setDefaultTimeZone('UTC');
    }
}
