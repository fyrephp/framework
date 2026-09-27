<?php
declare(strict_types=1);

namespace Tests\TestCase\DB\TypeParser;

use Fyre\DB\Types\DateTimeType;
use Fyre\Utility\DateTime\Date;
use Fyre\Utility\DateTime\DateTime;
use Fyre\Utility\DateTime\Time;
use PHPUnit\Framework\Attributes\DataProvider;

trait DateTimeTestTrait
{
    /**
     * @return array<string, array{string, int|string}>
     */
    public static function dateTimeFromDatabaseProvider(): array
    {
        return [
            'default' => ['2021-12-31T22:59:11.000+00:00', '2021-12-31 22:59:11'],
            'fractional' => ['2021-12-31T22:59:11.123+00:00', '2021-12-31 22:59:11.12345'],
            'timestamp' => ['2021-12-31T22:59:11.000+00:00', 1640991551],
        ];
    }

    #[DataProvider('dateTimeFromDatabaseProvider')]
    public function testDateTimeFromDatabase(string $expected, int|string $value): void
    {
        $this->assertSame(
            $expected,
            $this->type->use('datetime')->fromDatabase($value)?->toIsoString()
        );
    }

    public function testDateTimeFromDatabaseNull(): void
    {
        $this->assertNull(
            $this->type->use('datetime')->fromDatabase(null)
        );
    }

    public function testDateTimeFromDatabaseServerTimeZone(): void
    {
        $dateParser = $this->type->use('datetime');

        $this->assertInstanceOf(DateTimeType::class, $dateParser);

        $dateParser->setServerTimeZone('Australia/Brisbane');
        $date = $dateParser->fromDatabase('2021-12-31 22:59:11');

        $this->assertInstanceOf(
            DateTime::class,
            $date
        );

        $this->assertSame(
            '2021-12-31T12:59:11.000+00:00',
            $date->toIsoString()
        );
    }

    public function testDateTimeFromDatabaseUserTimeZone(): void
    {
        $dateParser = $this->type->use('datetime');

        $this->assertInstanceOf(DateTimeType::class, $dateParser);

        $dateParser->setUserTimeZone('Australia/Brisbane');
        $date = $dateParser->fromDatabase('2021-12-31 22:59:11');

        $this->assertInstanceOf(
            DateTime::class,
            $date
        );

        $this->assertSame(
            'Australia/Brisbane',
            $date->getTimeZone()
        );
    }

    public function testDateTimeGetValueClass(): void
    {
        $dateParser = $this->type->use('datetime');

        $this->assertInstanceOf(DateTimeType::class, $dateParser);

        $this->assertSame(
            DateTime::class,
            $dateParser->getValueClass()
        );
    }

    public function testDateTimeParse(): void
    {
        $date = $this->type->use('datetime')->parse('2022-01-01T08:59:11');

        $this->assertInstanceOf(
            DateTime::class,
            $date
        );

        $this->assertSame(
            '2022-01-01T08:59:11.000+00:00',
            $date->toIsoString()
        );
    }

    public function testDateTimeParseDate(): void
    {
        $this->assertNull(
            $this->type->use('datetime')->parse(Date::createFromArray([2021, 12, 31]))
        );
    }

    public function testDateTimeParseDateTime(): void
    {
        $date = DateTime::createFromTimestamp(1640991551);

        $this->assertSame(
            $date,
            $this->type->use('datetime')->parse($date)
        );
    }

    public function testDateTimeParseInvalid(): void
    {
        $this->assertNull(
            $this->type->use('datetime')->parse('invalid')
        );
    }

    public function testDateTimeParseLocaleFormat(): void
    {
        $dateParser = $this->type->use('datetime');

        $this->assertInstanceOf(DateTimeType::class, $dateParser);

        $this->assertSame(
            $dateParser,
            $dateParser->setLocaleFormat('eee MMM dd yyyy HH:mm:ss')
        );

        $date = $dateParser->parse('Sat Jan 01 2022 11:59:00');

        $this->assertInstanceOf(
            DateTime::class,
            $date
        );

        $this->assertSame(
            '2022-01-01T11:59:00.000+00:00',
            $date->toIsoString()
        );
    }

    public function testDateTimeParseLocaleFormatFallback(): void
    {
        $dateParser = $this->type->use('datetime');

        $this->assertInstanceOf(DateTimeType::class, $dateParser);

        $this->assertSame(
            $dateParser,
            $dateParser->setLocaleFormat('eee MMM dd yyyy HH:mm:ss')
        );

        $date = $dateParser->parse('2022-01-01T11:59:00');

        $this->assertInstanceOf(
            DateTime::class,
            $date
        );

        $this->assertSame(
            '2022-01-01T11:59:00.000+00:00',
            $date->toIsoString()
        );
    }

    public function testDateTimeParseNative(): void
    {
        $date = new \DateTime('@1640991551');

        $this->assertSame(
            '2021-12-31T22:59:11.000+00:00',
            $this->type->use('datetime')->parse($date)?->toIsoString()
        );
    }

    public function testDateTimeParseNull(): void
    {
        $this->assertNull(
            $this->type->use('datetime')->parse(null)
        );
    }

    public function testDateTimeParseTime(): void
    {
        $this->assertNull(
            $this->type->use('datetime')->parse(Time::createFromArray([22, 59, 11]))
        );
    }

    public function testDateTimeParseTimestamp(): void
    {
        $this->assertSame(
            '2021-12-31T22:59:11.000+00:00',
            $this->type->use('datetime')->parse(1640991551)?->toIsoString()
        );
    }

    public function testDateTimeParseUserTimeZone(): void
    {
        $dateParser = $this->type->use('datetime');

        $this->assertInstanceOf(DateTimeType::class, $dateParser);

        $dateParser->setUserTimeZone('Australia/Brisbane');
        $dateParser->setLocaleFormat('eee MMM dd yyyy HH:mm:ss');
        $date = $dateParser->parse('Sat Jan 01 2022 00:00:00');

        $this->assertInstanceOf(
            DateTime::class,
            $date
        );

        $this->assertSame(
            '2021-12-31T14:00:00.000+00:00',
            $date->toIsoString()
        );
    }

    public function testDateTimeSetLocaleFormat(): void
    {
        $dateParser = $this->type->use('datetime');

        $this->assertInstanceOf(DateTimeType::class, $dateParser);

        $this->assertSame(
            $dateParser,
            $dateParser->setLocaleFormat('eee MMM dd yyyy HH:mm:ss')
        );

        $this->assertSame(
            'eee MMM dd yyyy HH:mm:ss',
            $dateParser->getLocaleFormat()
        );
    }

    public function testDateTimeSetServerTimeZone(): void
    {
        $dateParser = $this->type->use('datetime');

        $this->assertInstanceOf(DateTimeType::class, $dateParser);

        $this->assertSame(
            $dateParser,
            $dateParser->setServerTimeZone('Australia/Brisbane')
        );

        $this->assertSame(
            'Australia/Brisbane',
            $dateParser->getServerTimeZone()
        );
    }

    public function testDateTimeSetUserTimeZone(): void
    {
        $dateParser = $this->type->use('datetime');

        $this->assertInstanceOf(DateTimeType::class, $dateParser);

        $this->assertSame(
            $dateParser,
            $dateParser->setUserTimeZone('Australia/Brisbane')
        );

        $this->assertSame(
            'Australia/Brisbane',
            $dateParser->getUserTimeZone()
        );
    }

    public function testDateTimeToDatabase(): void
    {
        $date = DateTime::createFromTimestamp(1640991551);

        $this->assertSame(
            '2021-12-31 22:59:11',
            $this->type->use('datetime')->toDatabase($date)
        );
    }

    public function testDateTimeToDatabaseNull(): void
    {
        $this->assertNull(
            $this->type->use('datetime')->toDatabase(null)
        );
    }

    public function testDateTimeToDatabaseServerTimeZone(): void
    {
        $dateParser = $this->type->use('datetime');

        $this->assertInstanceOf(DateTimeType::class, $dateParser);

        $dateParser->setServerTimeZone('Australia/Brisbane');

        $date = DateTime::createFromTimestamp(1640991551);

        $this->assertSame(
            '2022-01-01 08:59:11',
            $dateParser->toDatabase($date)
        );
    }

    public function testDateTimeToDatabaseString(): void
    {
        $this->assertSame(
            '2021-12-31 22:59:11',
            $this->type->use('datetime')->toDatabase('2021-12-31 22:59:11')
        );
    }
}
