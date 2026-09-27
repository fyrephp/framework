<?php
declare(strict_types=1);

namespace Tests\TestCase\DB\TypeParser;

use Fyre\DB\Types\DateType;
use Fyre\Utility\DateTime\Date;
use Fyre\Utility\DateTime\DateTime;
use Fyre\Utility\DateTime\Time;

trait DateTestTrait
{
    public function testDateFromDatabase(): void
    {
        $this->assertSame(
            '2021-12-31',
            $this->type->use('date')->fromDatabase('2021-12-31')?->toIsoString()
        );
    }

    public function testDateFromDatabaseNull(): void
    {
        $this->assertNull(
            $this->type->use('date')->fromDatabase(null)
        );
    }

    public function testDateFromDatabaseServerTimeZone(): void
    {
        $dateParser = $this->type->use('date');

        $this->assertInstanceOf(DateType::class, $dateParser);

        $dateParser->setServerTimeZone('Australia/Brisbane');
        $date = $dateParser->fromDatabase('2021-12-31');

        $this->assertInstanceOf(
            Date::class,
            $date
        );

        $this->assertSame(
            '2021-12-31',
            $date->toIsoString()
        );
    }

    public function testDateFromDatabaseTimestamp(): void
    {
        $this->assertSame(
            '2021-12-31',
            $this->type->use('date')->fromDatabase(1640991551)?->toIsoString()
        );
    }

    public function testDateFromDatabaseUserTimeZone(): void
    {
        $dateParser = $this->type->use('date');

        $this->assertInstanceOf(DateType::class, $dateParser);

        $dateParser->setUserTimeZone('Australia/Brisbane');
        $date = $dateParser->fromDatabase('2021-12-31');

        $this->assertInstanceOf(
            Date::class,
            $date
        );

        $this->assertSame(
            'UTC',
            $date->getTimeZone()
        );
    }

    public function testDateGetValueClass(): void
    {
        $dateParser = $this->type->use('date');

        $this->assertInstanceOf(DateType::class, $dateParser);

        $this->assertSame(
            Date::class,
            $dateParser->getValueClass()
        );
    }

    public function testDateParse(): void
    {
        $date = $this->type->use('date')->parse('2022-01-01');

        $this->assertInstanceOf(
            Date::class,
            $date
        );

        $this->assertSame(
            '2022-01-01',
            $date->toIsoString()
        );
    }

    public function testDateParseDate(): void
    {
        $date = Date::createFromTimestamp(1640991551);

        $this->assertSame(
            $date,
            $this->type->use('date')->parse($date)
        );
    }

    public function testDateParseDateTime(): void
    {
        $this->assertNull(
            $this->type->use('date')->parse(DateTime::createFromArray([2021, 12, 31]))
        );
    }

    public function testDateParseInvalid(): void
    {
        $this->assertNull(
            $this->type->use('date')->parse('invalid')
        );
    }

    public function testDateParseLocaleFormat(): void
    {
        $dateParser = $this->type->use('date');

        $this->assertInstanceOf(DateType::class, $dateParser);

        $this->assertSame(
            $dateParser,
            $dateParser->setLocaleFormat('eee MMM dd yyyy')
        );

        $date = $dateParser->parse('Sat Jan 01 2022');

        $this->assertInstanceOf(
            Date::class,
            $date
        );

        $this->assertSame(
            '2022-01-01',
            $date->toIsoString()
        );
    }

    public function testDateParseLocaleFormatFallback(): void
    {
        $dateParser = $this->type->use('date');

        $this->assertInstanceOf(DateType::class, $dateParser);

        $this->assertSame(
            $dateParser,
            $dateParser->setLocaleFormat('eee MMM dd yyyy')
        );

        $date = $dateParser->parse('2022-01-01');

        $this->assertInstanceOf(
            Date::class,
            $date
        );

        $this->assertSame(
            '2022-01-01',
            $date->toIsoString()
        );
    }

    public function testDateParseNative(): void
    {
        $date = new \DateTime('@1640991551');

        $this->assertSame(
            '2021-12-31',
            $this->type->use('date')->parse($date)?->toIsoString()
        );
    }

    public function testDateParseNull(): void
    {
        $this->assertNull(
            $this->type->use('date')->parse(null)
        );
    }

    public function testDateParseTime(): void
    {
        $this->assertNull(
            $this->type->use('date')->parse(Time::createFromArray([22, 59, 11]))
        );
    }

    public function testDateParseTimestamp(): void
    {
        $this->assertSame(
            '2021-12-31',
            $this->type->use('date')->parse(1640991551)?->toIsoString()
        );
    }

    public function testDateParseUserTimeZone(): void
    {
        $dateParser = $this->type->use('date');

        $this->assertInstanceOf(DateType::class, $dateParser);

        $dateParser->setUserTimeZone('Australia/Brisbane');
        $dateParser->setLocaleFormat('eee MMM dd yyyy');
        $date = $dateParser->parse('Sat Jan 01 2022');

        $this->assertInstanceOf(
            Date::class,
            $date
        );

        $this->assertSame(
            '2022-01-01',
            $date->toIsoString()
        );
    }

    public function testDateSetLocaleFormat(): void
    {
        $dateParser = $this->type->use('date');

        $this->assertInstanceOf(DateType::class, $dateParser);

        $this->assertSame(
            $dateParser,
            $dateParser->setLocaleFormat('eee MMM dd yyyy')
        );

        $this->assertSame(
            'eee MMM dd yyyy',
            $dateParser->getLocaleFormat()
        );
    }

    public function testDateSetServerTimeZone(): void
    {
        $dateParser = $this->type->use('date');

        $this->assertInstanceOf(DateType::class, $dateParser);

        $this->assertSame(
            $dateParser,
            $dateParser->setServerTimeZone('Australia/Brisbane')
        );

        $this->assertSame(
            'Australia/Brisbane',
            $dateParser->getServerTimeZone()
        );
    }

    public function testDateSetUserTimeZone(): void
    {
        $dateParser = $this->type->use('date');

        $this->assertInstanceOf(DateType::class, $dateParser);

        $this->assertSame(
            $dateParser,
            $dateParser->setUserTimeZone('Australia/Brisbane')
        );

        $this->assertSame(
            'Australia/Brisbane',
            $dateParser->getUserTimeZone()
        );
    }

    public function testDateToDatabase(): void
    {
        $date = Date::createFromTimestamp(1640991551);

        $this->assertSame(
            '2021-12-31',
            $this->type->use('date')->toDatabase($date)
        );
    }

    public function testDateToDatabaseNull(): void
    {
        $this->assertNull(
            $this->type->use('date')->toDatabase(null)
        );
    }

    public function testDateToDatabaseServerTimeZone(): void
    {
        $dateParser = $this->type->use('date');

        $this->assertInstanceOf(DateType::class, $dateParser);

        $dateParser->setServerTimeZone('Australia/Brisbane');

        $date = Date::createFromTimestamp(1640991551);

        $this->assertSame(
            '2021-12-31',
            $dateParser->toDatabase($date)
        );
    }

    public function testDateToDatabaseString(): void
    {
        $this->assertSame(
            '2021-12-31',
            $this->type->use('date')->toDatabase('2021-12-31')
        );
    }
}
