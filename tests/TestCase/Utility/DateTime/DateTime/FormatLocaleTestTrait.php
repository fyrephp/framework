<?php
declare(strict_types=1);

namespace Tests\TestCase\Utility\DateTime\DateTime;

use Fyre\Utility\DateTime\DateTime;
use PHPUnit\Framework\Attributes\DataProvider;

trait FormatLocaleTestTrait
{
    /**
     * @return array<string, array{int[], string, string, string}>
     */
    public static function formatLocaleProvider(): array
    {
        return [
            '11 hour 1 digit' => [[2018, 1, 1, 23], 'ar-eg', 'K', '١١'],
            '11 hour 1 digit padding' => [[2018, 1, 1, 0], 'ar-eg', 'K', '٠'],
            '11 hour 2 digits' => [[2018, 1, 1, 23], 'ar-eg', 'KK', '١١'],
            '11 hour 2 digits padding' => [[2018, 1, 1, 0], 'ar-eg', 'KK', '٠٠'],
            '12 hour 1 digit' => [[2018, 1, 1, 12], 'ar-eg', 'h', '١٢'],
            '12 hour 1 digit padding' => [[2018, 1, 1, 1], 'ar-eg', 'h', '١'],
            '12 hour 2 digits' => [[2018, 1, 1, 23], 'ar-eg', 'hh', '١١'],
            '12 hour 2 digits padding' => [[2018, 1, 1, 1], 'ar-eg', 'hh', '٠١'],
            '23 hour 1 digit' => [[2018, 1, 1, 23], 'ar-eg', 'H', '٢٣'],
            '23 hour 1 digit padding' => [[2018, 1, 1, 0], 'ar-eg', 'H', '٠'],
            '23 hour 2 digits' => [[2018, 1, 1, 23], 'ar-eg', 'HH', '٢٣'],
            '23 hour 2 digits padding' => [[2018, 1, 1, 0], 'ar-eg', 'HH', '٠٠'],
            '24 hour 1 digit' => [[2018, 1, 1, 0], 'ar-eg', 'k', '٢٤'],
            '24 hour 1 digit padding' => [[2018, 1, 1, 1], 'ar-eg', 'k', '١'],
            '24 hour 2 digits' => [[2018, 1, 1, 0], 'ar-eg', 'kk', '٢٤'],
            '24 hour 2 digits padding' => [[2018, 1, 1, 1], 'ar-eg', 'kk', '٠١'],
            'alt week day long' => [[2018, 6, 1], 'ru', 'EEEE', 'пятница'],
            'alt week day short' => [[2018, 6, 1], 'ru', 'EEE', 'пт'],
            'day of month 1 digit' => [[2019, 1, 21], 'ar-eg', 'd', '٢١'],
            'day of month 1 digit padding' => [[2019, 1, 1], 'ar-eg', 'd', '١'],
            'day of month 2 digits' => [[2019, 1, 21], 'ar-eg', 'dd', '٢١'],
            'day of month 2 digits padding' => [[2019, 1, 1], 'ar-eg', 'dd', '٠١'],
            'day of week in month' => [[2019, 1, 1], 'ar-eg', 'F', '١'],
            'day of week in month current week' => [[2019, 6, 7], 'ar-eg', 'F', '١'],
            'day of year 1 digit' => [[2019, 6, 1], 'ar-eg', 'D', '١٥٢'],
            'day of year 1 digit padding' => [[2019, 1, 1], 'ar-eg', 'D', '١'],
            'day of year 2 digits' => [[2019, 6, 1], 'ar-eg', 'DD', '١٥٢'],
            'day of year 2 digits padding' => [[2019, 1, 1], 'ar-eg', 'DD', '٠١'],
            'day of year 3 digits' => [[2019, 6, 1], 'ar-eg', 'DDD', '١٥٢'],
            'day of year 3 digits padding' => [[2019, 1, 1], 'ar-eg', 'DDD', '٠٠١'],
            'day period long am' => [[2018, 1, 1, 0], 'zh', 'aaaa', '上午'],
            'day period long pm' => [[2018, 1, 1, 12], 'zh', 'aaaa', '下午'],
            'day period short am' => [[2018, 1, 1, 0], 'zh', 'aaa', '上午'],
            'day period short pm' => [[2018, 1, 1, 12], 'zh', 'aaa', '下午'],
            'era long' => [[2018], 'ru', 'GGGG', 'от Рождества Христова'],
            'era long bc' => [[-5], 'ru', 'GGGG', 'до Рождества Христова'],
            'era narrow' => [[2018], 'ru', 'GGGGG', 'н.э.'],
            'era narrow bc' => [[-5], 'ru', 'GGGGG', 'до н.э.'],
            'era short' => [[2018], 'ru', 'GGG', 'н. э.'],
            'era short bc' => [[-5], 'ru', 'GGG', 'до н. э.'],
            'fractional' => [[2018, 1, 1, 0, 0, 0, 123], 'ar-eg', 'SSS', '١٢٣'],
            'fractional padding' => [[2018, 1, 1, 0, 0, 0, 123], 'ar-eg', 'SSSSSS', '١٢٣٠٠٠'],
            'fractional truncate' => [[2018, 1, 1, 0, 0, 0, 123], 'ar-eg', 'S', '١'],
            'minute 1 digit' => [[2018, 1, 1, 0, 25], 'ar-eg', 'm', '٢٥'],
            'minute 1 digit padding' => [[2018, 1, 1, 0, 1], 'ar-eg', 'm', '١'],
            'minute 2 digits' => [[2018, 1, 1, 0, 25], 'ar-eg', 'mm', '٢٥'],
            'minute 2 digits padding' => [[2018, 1, 1, 0, 1], 'ar-eg', 'mm', '٠١'],
            'month 1 digit' => [[2018, 10], 'ar-eg', 'M', '١٠'],
            'month 1 digit padding' => [[2018, 1], 'ar-eg', 'M', '١'],
            'month 2 digits' => [[2018, 10], 'ar-eg', 'MM', '١٠'],
            'month 2 digits padding' => [[2018, 1], 'ar-eg', 'MM', '٠١'],
            'month long' => [[2018, 10], 'ru', 'MMMM', 'октября'],
            'month narrow' => [[2018, 10], 'ru', 'MMMMM', 'О'],
            'month short' => [[2018, 10], 'ru', 'MMM', 'окт.'],
            'quarter 1 digit' => [[2018, 8], 'ar-eg', 'q', '٣'],
            'quarter 2 digits' => [[2018, 8], 'ar-eg', 'qq', '٠٣'],
            'second 1 digit' => [[2018, 1, 1, 0, 0, 25], 'ar-eg', 's', '٢٥'],
            'second 1 digit padding' => [[2018, 1, 1, 0, 0, 1], 'ar-eg', 's', '١'],
            'second 2 digits' => [[2018, 1, 1, 0, 0, 25], 'ar-eg', 'ss', '٢٥'],
            'second 2 digits padding' => [[2018, 1, 1, 0, 0, 1], 'ar-eg', 'ss', '٠١'],
            'standalone month 1 digit' => [[2018, 10], 'ar-eg', 'L', '١٠'],
            'standalone month 1 digit padding' => [[2018, 1], 'ar-eg', 'L', '١'],
            'standalone month 2 digits' => [[2018, 10], 'ar-eg', 'LL', '١٠'],
            'standalone month 2 digits padding' => [[2018, 1], 'ar-eg', 'LL', '٠١'],
            'standalone month long' => [[2018, 10], 'ru', 'LLLL', 'октябрь'],
            'standalone month narrow' => [[2018, 10], 'ru', 'LLLLL', 'О'],
            'standalone month short' => [[2018, 10], 'ru', 'LLL', 'окт.'],
            'standalone quarter 1 digit' => [[2018, 8], 'ar-eg', 'Q', '٣'],
            'standalone quarter 2 digits' => [[2018, 8], 'ar-eg', 'QQ', '٠٣'],
            // 'week day narrow' => [[2018, 6, 1], 'ru', 'eeeee', 'П'],
            'standalone week day 1 digit' => [[2018, 6, 1], 'ar-eg', 'c', '٧'],
            'standalone week day 2 digits' => [[2018, 6, 1], 'ar-eg', 'cc', '٧'],
            'standalone week day long' => [[2018, 6, 1], 'ru', 'cccc', 'пятница'],
            'standalone week day narrow' => [[2018, 6, 1], 'ru', 'ccccc', 'П'],
            'standalone week day short' => [[2018, 6, 1], 'ru', 'ccc', 'пт'],
            // 'alt week day narrow' => [[2018, 6, 1], 'ru', 'EEEEE', 'П'],
            'week day 1 digit' => [[2018, 6, 1], 'ar-eg', 'e', '٧'],
            'week day 2 digits' => [[2018, 6, 1], 'ar-eg', 'ee', '٠٧'],
            'week day long' => [[2018, 6, 1], 'ru', 'eeee', 'пятница'],
            'week day short' => [[2018, 6, 1], 'ru', 'eee', 'пт'],
            'week of month' => [[2019, 6, 1], 'ar-eg', 'W', '١'],
            'week of month current week' => [[2019, 6, 8], 'ar-eg', 'W', '٢'],
            'week of year 1 digit' => [[2018, 6], 'ar-eg', 'w', '٢٢'],
            'week of year 1 digit padding' => [[2018, 1], 'ar-eg', 'w', '١'],
            'week of year 2 digits' => [[2018, 6], 'ar-eg', 'ww', '٢٢'],
            'week of year 2 digits padding' => [[2018, 1], 'ar-eg', 'ww', '٠١'],
            'week year 1 digit' => [[2018], 'ar-eg', 'Y', '٢٠١٨'],
            'week year 1 digit current week' => [[2019, 12, 30], 'ar-eg', 'Y', '٢٠٢٠'],
            'week year 1 digit padding' => [[5, 2], 'ar-eg', 'Y', '٥'],
            'week year 2 digits' => [[2018], 'ar-eg', 'YY', '١٨'],
            'week year 2 digits current week' => [[2019, 12, 30], 'ar-eg', 'YY', '٢٠'],
            'week year 2 digits padding' => [[5, 2], 'ar-eg', 'YY', '٠٥'],
            'week year 3 digits' => [[2018], 'ar-eg', 'YYY', '٢٠١٨'],
            'week year 3 digits current week' => [[2019, 12, 30], 'ar-eg', 'YYY', '٢٠٢٠'],
            'week year 3 digits padding' => [[5], 'ar-eg', 'YYY', '٠٠٥'],
            'week year 4 digits' => [[2018], 'ar-eg', 'YYYY', '٢٠١٨'],
            'week year 4 digits current week' => [[2019, 12, 30], 'ar-eg', 'YYYY', '٢٠٢٠'],
            'week year 4 digits padding' => [[5], 'ar-eg', 'YYYY', '٠٠٠٥'],
            'year 1 digit' => [[2018], 'ar-eg', 'y', '٢٠١٨'],
            'year 1 digit padding' => [[5], 'ar-eg', 'y', '٥'],
            'year 2 digits' => [[2018], 'ar-eg', 'yy', '١٨'],
            'year 2 digits padding' => [[5], 'ar-eg', 'yy', '٠٥'],
            'year 3 digits' => [[2018], 'ar-eg', 'yyy', '٢٠١٨'],
            'year 3 digits padding' => [[5], 'ar-eg', 'yyy', '٠٠٥'],
            'year 4 digits' => [[2018], 'ar-eg', 'yyyy', '٢٠١٨'],
            'year 4 digits padding' => [[5], 'ar-eg', 'yyyy', '٠٠٠٥'],
        ];
    }

    /**
     * @return array<string, array{string|null, string, string, string, 4?: string}>
     */
    public static function timeZoneFormatLocaleProvider(): array
    {
        return [
            'ISO 8601 basic' => [null, 'ru', 'xx', '+0000'],
            'ISO 8601 basic alt' => [null, 'ru', 'ZZZ', '+0000'],
            'ISO 8601 basic alt time zone' => ['Australia/Brisbane', 'ru', 'ZZZ', '+1000'],
            'ISO 8601 basic short' => [null, 'ru', 'x', '+00'],
            'ISO 8601 basic short time zone' => ['Australia/Brisbane', 'ru', 'x', '+10'],
            'ISO 8601 basic short z' => [null, 'ru', 'X', 'Z'],
            'ISO 8601 basic short z time zone' => ['Australia/Brisbane', 'ru', 'X', '+10'],
            'ISO 8601 basic time zone' => ['Australia/Brisbane', 'ru', 'xx', '+1000'],
            'ISO 8601 basic z' => [null, 'ru', 'XX', 'Z'],
            'ISO 8601 basic z time zone' => ['Australia/Brisbane', 'ru', 'XX', '+1000'],
            'ISO 8601 extended' => [null, 'ru', 'xxx', '+00:00'],
            'ISO 8601 extended alt' => [null, 'ru', 'ZZZZZ', 'Z'],
            'ISO 8601 extended alt time zone' => ['Australia/Brisbane', 'ru', 'ZZZZZ', '+10:00'],
            'ISO 8601 extended time zone' => ['Australia/Brisbane', 'ru', 'xxx', '+10:00'],
            'ISO 8601 extended z' => [null, 'ru', 'XXX', 'Z'],
            'ISO 8601 extended z time zone' => ['Australia/Brisbane', 'ru', 'XXX', '+10:00'],
            'long basic' => [null, 'ru', 'ZZZZ', 'GMT', 'GMT+00:00'],
            'long basic time zone' => ['Australia/Brisbane', 'ru', 'ZZZZ', 'GMT+10:00'],
            'long localized' => [null, 'ru', 'OOOO', 'GMT', 'GMT+00:00'],
            'long localized time zone' => ['Australia/Brisbane', 'ru', 'OOOO', 'GMT+10:00'],
            'long non location' => [null, 'ru', 'zzzz', 'Всемирное координированное время'],
            // 'long non-location time zone' => ['Australia/Brisbane', 'ru', 'zzzz', 'Восточная Австралия, стандартное время'],
            'long time zone ID' => [null, 'ru', 'VV', 'UTC'],
            'long time zone ID time zone' => ['Australia/Brisbane', 'ru', 'VV', 'Australia/Brisbane'],
            'short localized' => [null, 'ru', 'O', 'GMT', 'GMT+0'],
            'short localized time zone' => ['Australia/Brisbane', 'ru', 'O', 'GMT+10'],
            'short non location' => [null, 'ru', 'zzz', 'UTC'],
            'short non location time zone' => ['Australia/Brisbane', 'ru', 'zzz', 'GMT+10'],
        ];
    }

    /**
     * @param int[] $parts
     */
    #[DataProvider('formatLocaleProvider')]
    public function testFormatLocale(array $parts, string $locale, string $format, string $expected): void
    {
        $this->assertSame(
            $expected,
            DateTime::createFromArray($parts, locale: $locale)->format($format)
        );
    }

    #[DataProvider('timeZoneFormatLocaleProvider')]
    public function testFormatLocaleTimeZone(
        string|null $timeZone,
        string $locale,
        string $format,
        string ...$expected
    ): void {
        $this->assertContains(
            DateTime::now($timeZone, $locale)->format($format),
            $expected
        );
    }
}
