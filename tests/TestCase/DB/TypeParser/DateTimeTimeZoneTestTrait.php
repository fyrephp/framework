<?php
declare(strict_types=1);

namespace Tests\TestCase\DB\TypeParser;

use Fyre\DB\Types\DateTimeType;
use Fyre\Utility\DateTime\DateTime;

trait DateTimeTimeZoneTestTrait
{
    public function testDateTimeTimeZoneFromDatabase(): void
    {
        $this->assertSame(
            '2021-12-31T12:59:11.123+00:00',
            $this->type->use('datetime-timezone')->fromDatabase('2021-12-31 22:59:11.12345+10')?->toIsoString()
        );
    }

    public function testDateTimeTimeZoneFromDatabaseNull(): void
    {
        $this->assertNull(
            $this->type->use('datetime-timezone')->fromDatabase(null)
        );
    }

    public function testDateTimeTimeZoneFromDatabaseServerTimeZone(): void
    {
        $dateParser = $this->type->use('datetime-timezone');

        $this->assertInstanceOf(DateTimeType::class, $dateParser);

        $dateParser->setServerTimeZone('Australia/Brisbane');
        $date = $dateParser->fromDatabase('2021-12-31 22:59:11.12345+10');

        $this->assertInstanceOf(
            DateTime::class,
            $date
        );

        $this->assertSame(
            '2021-12-31T12:59:11.123+00:00',
            $date->toIsoString()
        );
    }

    public function testDateTimeTimeZoneFromDatabaseTimestamp(): void
    {
        $this->assertSame(
            '2021-12-31T22:59:11.000+00:00',
            $this->type->use('datetime-timezone')->fromDatabase(1640991551)?->toIsoString()
        );
    }

    public function testDateTimeTimeZoneFromDatabaseUserTimeZone(): void
    {
        $dateParser = $this->type->use('datetime-timezone');

        $this->assertInstanceOf(DateTimeType::class, $dateParser);

        $dateParser->setUserTimeZone('Australia/Brisbane');
        $date = $dateParser->fromDatabase('2021-12-31 22:59:11.12345+10');

        $this->assertInstanceOf(
            DateTime::class,
            $date
        );

        $this->assertSame(
            'Australia/Brisbane',
            $date->getTimeZone()
        );
    }

    public function testDateTimeTimeZoneParse(): void
    {
        $date = $this->type->use('datetime-timezone')->parse('2022-01-01T08:59:11.12345+10');

        $this->assertInstanceOf(
            DateTime::class,
            $date
        );

        $this->assertSame(
            '2021-12-31T22:59:11.123+00:00',
            $date->toIsoString()
        );
    }

    public function testDateTimeTimeZoneParseDateTime(): void
    {
        $date = DateTime::createFromTimestamp(1640991551);

        $this->assertSame(
            $date,
            $this->type->use('datetime-timezone')->parse($date)
        );
    }

    public function testDateTimeTimeZoneParseInvalid(): void
    {
        $this->assertNull(
            $this->type->use('datetime-timezone')->parse('invalid')
        );
    }

    public function testDateTimeTimeZoneParseLocaleFormat(): void
    {
        $dateParser = $this->type->use('datetime-timezone');

        $this->assertInstanceOf(DateTimeType::class, $dateParser);

        $this->assertSame(
            $dateParser,
            $dateParser->setLocaleFormat('eee MMM dd yyyy HH:mm:ss.SSS')
        );

        $date = $dateParser->parse('Sat Jan 01 2022 11:59:00.123+10');

        $this->assertInstanceOf(
            DateTime::class,
            $date
        );

        $this->assertSame(
            '2022-01-01T11:59:00.000+00:00',
            $date->toIsoString()
        );
    }

    public function testDateTimeTimeZoneParseLocaleFormatFallback(): void
    {
        $dateParser = $this->type->use('datetime-timezone');

        $this->assertInstanceOf(DateTimeType::class, $dateParser);

        $this->assertSame(
            $dateParser,
            $dateParser->setLocaleFormat('eee MMM dd yyyy HH:mm:ss.SSS')
        );

        $date = $dateParser->parse('2022-01-01T21:59:00.000+10');

        $this->assertInstanceOf(
            DateTime::class,
            $date
        );

        $this->assertSame(
            '2022-01-01T11:59:00.000+00:00',
            $date->toIsoString()
        );
    }

    public function testDateTimeTimeZoneParseNative(): void
    {
        $date = new \DateTime('@1640991551');

        $this->assertSame(
            '2021-12-31T22:59:11.000+00:00',
            $this->type->use('datetime-timezone')->parse($date)?->toIsoString()
        );
    }

    public function testDateTimeTimeZoneParseNull(): void
    {
        $this->assertNull(
            $this->type->use('datetime-timezone')->parse(null)
        );
    }

    public function testDateTimeTimeZoneParseTimestamp(): void
    {
        $this->assertSame(
            '2021-12-31T22:59:11.000+00:00',
            $this->type->use('datetime-timezone')->parse(1640991551)?->toIsoString()
        );
    }

    public function testDateTimeTimeZoneParseUserTimeZone(): void
    {
        $dateParser = $this->type->use('datetime-timezone');

        $this->assertInstanceOf(DateTimeType::class, $dateParser);

        $dateParser->setUserTimeZone('Australia/Brisbane');
        $dateParser->setLocaleFormat('eee MMM dd yyyy HH:mm:ss.SSS');
        $date = $dateParser->parse('Sat Jan 01 2022 00:00:00.12345+10');

        $this->assertInstanceOf(
            DateTime::class,
            $date
        );

        $this->assertSame(
            '2021-12-31T14:00:00.000+00:00',
            $date->toIsoString()
        );
    }

    public function testDateTimeTimeZoneSetLocaleFormat(): void
    {
        $dateParser = $this->type->use('datetime-timezone');

        $this->assertInstanceOf(DateTimeType::class, $dateParser);

        $this->assertSame(
            $dateParser,
            $dateParser->setLocaleFormat('eee MMM dd yyyy HH:mm:ss.SSS')
        );

        $this->assertSame(
            'eee MMM dd yyyy HH:mm:ss.SSS',
            $dateParser->getLocaleFormat()
        );
    }

    public function testDateTimeTimeZoneSetServerTimeZone(): void
    {
        $dateParser = $this->type->use('datetime-timezone');

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

    public function testDateTimeTimeZoneSetUserTimeZone(): void
    {
        $dateParser = $this->type->use('datetime-timezone');

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

    public function testDateTimeTimeZoneToDatabase(): void
    {
        $date = DateTime::createFromTimestamp(1640991551);

        $this->assertSame(
            '2021-12-31 22:59:11.000000+00:00',
            $this->type->use('datetime-timezone')->toDatabase($date)
        );
    }

    public function testDateTimeTimeZoneToDatabaseNull(): void
    {
        $this->assertNull(
            $this->type->use('datetime-timezone')->toDatabase(null)
        );
    }

    public function testDateTimeTimeZoneToDatabaseServerTimeZone(): void
    {
        $dateParser = $this->type->use('datetime-timezone');

        $this->assertInstanceOf(DateTimeType::class, $dateParser);

        $dateParser->setServerTimeZone('Australia/Brisbane');

        $date = DateTime::createFromTimestamp(1640991551);

        $this->assertSame(
            '2022-01-01 08:59:11.000000+10:00',
            $dateParser->toDatabase($date)
        );
    }

    public function testDateTimeTimeZoneToDatabaseString(): void
    {
        $this->assertSame(
            '2021-12-31 22:59:11.123000+10:00',
            $this->type->use('datetime-timezone')->toDatabase('2021-12-31 22:59:11.12345+10')
        );
    }
}
