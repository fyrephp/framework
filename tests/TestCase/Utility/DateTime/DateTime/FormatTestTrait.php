<?php
declare(strict_types=1);

namespace Tests\TestCase\Utility\DateTime\DateTime;

use Fyre\Utility\DateTime\DateTime;
use PHPUnit\Framework\Attributes\DataProvider;

trait FormatTestTrait
{
    /**
     * @return array<string, array{int[], string, string}>
     */
    public static function formatProvider(): array
    {
        return [
            '11 hour 1 digit' => [[2018, 1, 1, 23], 'K', '11'],
            '11 hour 1 digit padding' => [[2018, 1, 1, 0], 'K', '0'],
            '11 hour 2 digits' => [[2018, 1, 1, 23], 'KK', '11'],
            '11 hour 2 digits padding' => [[2018, 1, 1, 0], 'KK', '00'],
            '12 hour 1 digit' => [[2018, 1, 1, 12], 'h', '12'],
            '12 hour 1 digit padding' => [[2018, 1, 1, 1], 'h', '1'],
            '12 hour 2 digits' => [[2018, 1, 1, 23], 'hh', '11'],
            '12 hour 2 digits padding' => [[2018, 1, 1, 1], 'hh', '01'],
            '23 hour 1 digit' => [[2018, 1, 1, 23], 'H', '23'],
            '23 hour 1 digit padding' => [[2018, 1, 1, 0], 'H', '0'],
            '23 hour 2 digits' => [[2018, 1, 1, 23], 'HH', '23'],
            '23 hour 2 digits padding' => [[2018, 1, 1, 0], 'HH', '00'],
            '24 hour 1 digit' => [[2018, 1, 1, 0], 'k', '24'],
            '24 hour 1 digit padding' => [[2018, 1, 1, 1], 'k', '1'],
            '24 hour 2 digits' => [[2018, 1, 1, 0], 'kk', '24'],
            '24 hour 2 digits padding' => [[2018, 1, 1, 1], 'kk', '01'],
            'alt week day long' => [[2018, 6, 1], 'EEEE', 'Friday'],
            'alt week day narrow' => [[2018, 6, 1], 'EEEEE', 'F'],
            'alt week day short' => [[2018, 6, 1], 'EEE', 'Fri'],
            'day of month 1 digit' => [[2018, 1, 21], 'd', '21'],
            'day of month 1 digit padding' => [[2018, 1, 1], 'd', '1'],
            'day of month 2 digits' => [[2018, 1, 21], 'dd', '21'],
            'day of month 2 digits padding' => [[2018, 1, 1], 'dd', '01'],
            'day of week in month' => [[2018, 6, 1], 'F', '1'],
            'day of week in month current week' => [[2018, 6, 7], 'F', '1'],
            'day of year 1 digit' => [[2018, 6, 1], 'D', '152'],
            'day of year 1 digit padding' => [[2018, 1, 1], 'D', '1'],
            'day of year 2 digits' => [[2018, 6, 1], 'DD', '152'],
            'day of year 2 digits padding' => [[2018, 1, 1], 'DD', '01'],
            'day of year 3 digits' => [[2018, 6, 1], 'DDD', '152'],
            'day of year 3 digits padding' => [[2018, 1, 1], 'DDD', '001'],
            'day period long am' => [[2018, 1, 1, 0], 'aaaa', 'AM'],
            'day period long pm' => [[2018, 1, 1, 12], 'aaaa', 'PM'],
            'day period short am' => [[2018, 1, 1, 0], 'aaa', 'AM'],
            'day period short pm' => [[2018, 1, 1, 12], 'aaa', 'PM'],
            'era long' => [[2018], 'GGGG', 'Anno Domini'],
            'era long bc' => [[-5], 'GGGG', 'Before Christ'],
            'era narrow' => [[2018], 'GGGGG', 'A'],
            'era narrow bc' => [[-5], 'GGGGG', 'B'],
            'era short' => [[2018], 'GGG', 'AD'],
            'era short bc' => [[-5], 'GGG', 'BC'],
            'fractional' => [[2018, 1, 1, 0, 0, 0, 123], 'SSS', '123'],
            'fractional padding' => [[2018, 1, 1, 0, 0, 0, 123], 'SSSSSS', '123000'],
            'fractional truncate' => [[2018, 1, 1, 0, 0, 0, 123], 'S', '1'],
            'minute 1 digit' => [[2018, 1, 1, 0, 25], 'm', '25'],
            'minute 1 digit padding' => [[2018, 1, 1, 0, 1], 'm', '1'],
            'minute 2 digits' => [[2018, 1, 1, 0, 25], 'mm', '25'],
            'minute 2 digits padding' => [[2018, 1, 1, 0, 1], 'mm', '01'],
            'month 1 digit' => [[2018, 10], 'M', '10'],
            'month 1 digit padding' => [[2018, 1], 'M', '1'],
            'month 2 digits' => [[2018, 10], 'MM', '10'],
            'month 2 digits padding' => [[2018, 1], 'MM', '01'],
            'month long' => [[2018, 10], 'MMMM', 'October'],
            'month narrow' => [[2018, 10], 'MMMMM', 'O'],
            'month short' => [[2018, 10], 'MMM', 'Oct'],
            'quarter 1 digit' => [[2018, 8], 'q', '3'],
            'quarter 2 digits' => [[2018, 8], 'qq', '03'],
            'second 1 digit' => [[2018, 1, 1, 0, 0, 25], 's', '25'],
            'second 1 digit padding' => [[2018, 1, 1, 0, 0, 1], 's', '1'],
            'second 2 digits' => [[2018, 1, 1, 0, 0, 25], 'ss', '25'],
            'second 2 digits padding' => [[2018, 1, 1, 0, 0, 1], 'ss', '01'],
            'standalone month 1 digit' => [[2018, 10], 'L', '10'],
            'standalone month 1 digit padding' => [[2018, 1], 'L', '1'],
            'standalone month 2 digits' => [[2018, 10], 'LL', '10'],
            'standalone month 2 digits padding' => [[2018, 1], 'LL', '01'],
            'standalone month long' => [[2018, 10], 'LLLL', 'October'],
            'standalone month narrow' => [[2018, 10], 'LLLLL', 'O'],
            'standalone month short' => [[2018, 10], 'LLL', 'Oct'],
            'standalone quarter 1 digit' => [[2018, 8], 'Q', '3'],
            'standalone quarter 2 digits' => [[2018, 8], 'QQ', '03'],
            'standalone week day 1 digit' => [[2018, 6, 1], 'c', '6'],
            'standalone week day 2 digits' => [[2018, 6, 1], 'cc', '6'],
            'standalone week day long' => [[2018, 6, 1], 'cccc', 'Friday'],
            'standalone week day narrow' => [[2018, 6, 1], 'ccccc', 'F'],
            'standalone week day short' => [[2018, 6, 1], 'ccc', 'Fri'],
            'week day 1 digit' => [[2018, 6, 1], 'e', '6'],
            'week day 2 digits' => [[2018, 6, 1], 'ee', '06'],
            'week day long' => [[2018, 6, 1], 'eeee', 'Friday'],
            'week day narrow' => [[2018, 6, 1], 'eeeee', 'F'],
            'week day short' => [[2018, 6, 1], 'eee', 'Fri'],
            'week of month' => [[2018, 6, 1], 'W', '1'],
            'week of month current week' => [[2018, 6, 3], 'W', '2'],
            'week of year 1 digit' => [[2018, 6], 'w', '22'],
            'week of year 1 digit padding' => [[2018, 1], 'w', '1'],
            'week of year 2 digits' => [[2018, 6], 'ww', '22'],
            'week of year 2 digits padding' => [[2018, 1], 'ww', '01'],
            'week year 1 digit' => [[2018], 'Y', '2018'],
            'week year 1 digit current week' => [[2019, 12, 30], 'Y', '2020'],
            'week year 1 digit padding' => [[5], 'Y', '5'],
            'week year 2 digits' => [[2018], 'YY', '18'],
            'week year 2 digits current week' => [[2019, 12, 30], 'YY', '20'],
            'week year 2 digits padding' => [[5], 'YY', '05'],
            'week year 3 digits' => [[2018], 'YYY', '2018'],
            'week year 3 digits current week' => [[2019, 12, 30], 'YYY', '2020'],
            'week year 3 digits padding' => [[5], 'YYY', '005'],
            'week year 4 digits' => [[2018], 'YYYY', '2018'],
            'week year 4 digits current week' => [[2019, 12, 30], 'YYYY', '2020'],
            'week year 4 digits padding' => [[5], 'YYYY', '0005'],
            'year 1 digit' => [[2018], 'y', '2018'],
            'year 1 digit padding' => [[5], 'y', '5'],
            'year 2 digits' => [[2018], 'yy', '18'],
            'year 2 digits padding' => [[5], 'yy', '05'],
            'year 3 digits' => [[2018], 'yyy', '2018'],
            'year 3 digits padding' => [[5], 'yyy', '005'],
            'year 4 digits' => [[2018], 'yyyy', '2018'],
            'year 4 digits padding' => [[5], 'yyyy', '0005'],
        ];
    }

    /**
     * @return array<string, array{string|null, string, string, 3?: string}>
     */
    public static function timeZoneFormatProvider(): array
    {
        return [
            'ISO 8601 basic' => [null, 'xx', '+0000'],
            'ISO 8601 basic alt' => [null, 'ZZZ', '+0000'],
            'ISO 8601 basic alt time zone' => ['Australia/Brisbane', 'ZZZ', '+1000'],
            'ISO 8601 basic short' => [null, 'x', '+00'],
            'ISO 8601 basic short time zone' => ['Australia/Brisbane', 'x', '+10'],
            'ISO 8601 basic short Z' => [null, 'X', 'Z'],
            'ISO 8601 basic short Z time zone' => ['Australia/Brisbane', 'X', '+10'],
            'ISO 8601 basic time zone' => ['Australia/Brisbane', 'xx', '+1000'],
            'ISO 8601 basic Z' => [null, 'XX', 'Z'],
            'ISO 8601 basic Z time zone' => ['Australia/Brisbane', 'XX', '+1000'],
            'ISO 8601 extended' => [null, 'xxx', '+00:00'],
            'ISO 8601 extended alt' => [null, 'ZZZZZ', 'Z'],
            'ISO 8601 extended alt time zone' => ['Australia/Brisbane', 'ZZZZZ', '+10:00'],
            'ISO 8601 extended time zone' => ['Australia/Brisbane', 'xxx', '+10:00'],
            'ISO 8601 extended Z' => [null, 'XXX', 'Z'],
            'ISO 8601 extended Z time zone' => ['Australia/Brisbane', 'XXX', '+10:00'],
            'long basic' => [null, 'ZZZZ', 'GMT', 'GMT+00:00'],
            'long basic time zone' => ['Australia/Brisbane', 'ZZZZ', 'GMT+10:00'],
            'long localized' => [null, 'OOOO', 'GMT', 'GMT+00:00'],
            'long localized time zone' => ['Australia/Brisbane', 'OOOO', 'GMT+10:00'],
            'long non-location' => [null, 'zzzz', 'Coordinated Universal Time'],
            'long non-location time zone' => ['Australia/Brisbane', 'zzzz', 'Australian Eastern Standard Time'],
            'long time zone ID' => [null, 'VV', 'UTC'],
            'long time zone ID time zone' => ['Australia/Brisbane', 'VV', 'Australia/Brisbane'],
            'short localized' => [null, 'O', 'GMT', 'GMT+0'],
            'short localized time zone' => ['Australia/Brisbane', 'O', 'GMT+10'],
            'short non-location' => [null, 'zzz', 'UTC'],
            'short non-location time zone' => ['Australia/Brisbane', 'zzz', 'GMT+10'],
        ];
    }

    /**
     * @param int[] $parts
     */
    #[DataProvider('formatProvider')]
    public function testFormat(array $parts, string $format, string $expected): void
    {
        $this->assertSame(
            $expected,
            DateTime::createFromArray($parts)->format($format)
        );
    }

    #[DataProvider('timeZoneFormatProvider')]
    public function testFormatTimeZone(string|null $timeZone, string $format, string ...$expected): void
    {
        $this->assertContains(
            DateTime::now($timeZone)->format($format),
            $expected
        );
    }
}
