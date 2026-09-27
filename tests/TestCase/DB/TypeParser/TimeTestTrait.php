<?php
declare(strict_types=1);

namespace Tests\TestCase\DB\TypeParser;

use Fyre\DB\Types\TimeType;
use Fyre\Utility\DateTime\Date;
use Fyre\Utility\DateTime\DateTime;
use Fyre\Utility\DateTime\Time;
use PHPUnit\Framework\Attributes\DataProvider;

trait TimeTestTrait
{
    /**
     * @return array<string, array{string, int|string}>
     */
    public static function timeFromDatabaseProvider(): array
    {
        return [
            'default' => ['22:59:11', '22:59:11'],
            'fractional' => ['22:59:11.123', '22:59:11.12345'],
            'timestamp' => ['22:59:11', 1640991551],
        ];
    }

    #[DataProvider('timeFromDatabaseProvider')]
    public function testTimeFromDatabase(string $expected, int|string $value): void
    {
        $this->assertSame(
            $expected,
            $this->type->use('time')->fromDatabase($value)?->toIsoString()
        );
    }

    public function testTimeFromDatabaseNull(): void
    {
        $this->assertNull(
            $this->type->use('time')->fromDatabase(null)
        );
    }

    public function testTimeFromDatabaseServerTimeZone(): void
    {
        $timeParser = $this->type->use('time');

        $this->assertInstanceOf(TimeType::class, $timeParser);

        $timeParser->setServerTimeZone('Australia/Brisbane');
        $time = $timeParser->fromDatabase('22:59:11');

        $this->assertInstanceOf(
            Time::class,
            $time
        );

        $this->assertSame(
            'UTC',
            $time->getTimeZone()
        );
    }

    public function testTimeFromDatabaseUserTimeZone(): void
    {
        $timeParser = $this->type->use('time');

        $this->assertInstanceOf(TimeType::class, $timeParser);

        $timeParser->setUserTimeZone('Australia/Brisbane');
        $time = $timeParser->fromDatabase('22:59:11');

        $this->assertInstanceOf(
            Time::class,
            $time
        );

        $this->assertSame(
            'UTC',
            $time->getTimeZone()
        );
    }

    public function testTimeGetValueClass(): void
    {
        $timeParser = $this->type->use('time');

        $this->assertInstanceOf(TimeType::class, $timeParser);

        $this->assertSame(
            Time::class,
            $timeParser->getValueClass()
        );
    }

    public function testTimeParse(): void
    {
        $time = $this->type->use('time')->parse('08:59:11');

        $this->assertInstanceOf(
            Time::class,
            $time
        );

        $this->assertSame(
            '08:59:11',
            $time->toIsoString()
        );
    }

    public function testTimeParseDate(): void
    {
        $this->assertNull(
            $this->type->use('time')->parse(Date::createFromArray([2021, 12, 31]))
        );
    }

    public function testTimeParseDateTime(): void
    {
        $this->assertNull(
            $this->type->use('time')->parse(DateTime::createFromArray([2021, 12, 31, 22, 59, 11]))
        );
    }

    public function testTimeParseInvalid(): void
    {
        $this->assertNull(
            $this->type->use('time')->parse('invalid')
        );
    }

    public function testTimeParseLocaleFormat(): void
    {
        $timeParser = $this->type->use('time');

        $this->assertInstanceOf(TimeType::class, $timeParser);

        $this->assertSame(
            $timeParser,
            $timeParser->setLocaleFormat('HH:mm:ss')
        );

        $time = $timeParser->parse('11:59:00');

        $this->assertInstanceOf(
            Time::class,
            $time
        );

        $this->assertSame(
            '11:59:00',
            $time->toIsoString()
        );
    }

    public function testTimeParseLocaleFormatFallback(): void
    {
        $timeParser = $this->type->use('time');

        $this->assertInstanceOf(TimeType::class, $timeParser);

        $this->assertSame(
            $timeParser,
            $timeParser->setLocaleFormat('hh:mm:ss aa')
        );

        $time = $timeParser->parse('11:59:00');

        $this->assertInstanceOf(
            Time::class,
            $time
        );

        $this->assertSame(
            '11:59:00',
            $time->toIsoString()
        );
    }

    public function testTimeParseNative(): void
    {
        $time = new \DateTime('@1640991551');

        $this->assertSame(
            '22:59:11',
            $this->type->use('time')->parse($time)?->toIsoString()
        );
    }

    public function testTimeParseNull(): void
    {
        $this->assertNull(
            $this->type->use('time')->parse(null)
        );
    }

    public function testTimeParseTime(): void
    {
        $time = Time::createFromTimestamp(1640991551);

        $this->assertSame(
            $time,
            $this->type->use('time')->parse($time)
        );
    }

    public function testTimeParseTimestamp(): void
    {
        $this->assertSame(
            '22:59:11',
            $this->type->use('time')->parse(1640991551)?->toIsoString()
        );
    }

    public function testTimeParseUserTimeZone(): void
    {
        $timeParser = $this->type->use('time');

        $this->assertInstanceOf(TimeType::class, $timeParser);

        $timeParser->setUserTimeZone('Australia/Brisbane');
        $timeParser->setLocaleFormat('HH:mm:ss');
        $time = $timeParser->parse('00:00:00');

        $this->assertInstanceOf(
            Time::class,
            $time
        );

        $this->assertSame(
            'UTC',
            $time->getTimeZone()
        );
    }

    public function testTimeSetLocaleFormat(): void
    {
        $timeParser = $this->type->use('time');

        $this->assertInstanceOf(TimeType::class, $timeParser);

        $this->assertSame(
            $timeParser,
            $timeParser->setLocaleFormat('HH:mm:ss')
        );

        $this->assertSame(
            'HH:mm:ss',
            $timeParser->getLocaleFormat()
        );
    }

    public function testTimeSetServerTimeZone(): void
    {
        $timeParser = $this->type->use('time');

        $this->assertInstanceOf(TimeType::class, $timeParser);

        $this->assertSame(
            $timeParser,
            $timeParser->setServerTimeZone('Australia/Brisbane')
        );

        $this->assertSame(
            'Australia/Brisbane',
            $timeParser->getServerTimeZone()
        );
    }

    public function testTimeSetUserTimeZone(): void
    {
        $timeParser = $this->type->use('time');

        $this->assertInstanceOf(TimeType::class, $timeParser);

        $this->assertSame(
            $timeParser,
            $timeParser->setUserTimeZone('Australia/Brisbane')
        );

        $this->assertSame(
            'Australia/Brisbane',
            $timeParser->getUserTimeZone()
        );
    }

    public function testTimeToDatabase(): void
    {
        $time = Time::createFromTimestamp(1640991551);

        $this->assertSame(
            '22:59:11.000',
            $this->type->use('time')->toDatabase($time)
        );
    }

    public function testTimeToDatabaseFractional(): void
    {
        $time = Time::createFromArray([22, 59, 11, 123]);

        $this->assertSame(
            '22:59:11.123',
            $this->type->use('time')->toDatabase($time)
        );
    }

    public function testTimeToDatabaseNull(): void
    {
        $this->assertNull(
            $this->type->use('time')->toDatabase(null)
        );
    }

    public function testTimeToDatabaseServerTimeZone(): void
    {
        $timeParser = $this->type->use('time');

        $this->assertInstanceOf(TimeType::class, $timeParser);

        $timeParser->setServerTimeZone('Australia/Brisbane');

        $time = Time::createFromTimestamp(1640991551);

        $this->assertSame(
            '22:59:11.000',
            $timeParser->toDatabase($time)
        );
    }

    public function testTimeToDatabaseString(): void
    {
        $this->assertSame(
            '22:59:11.000',
            $this->type->use('time')->toDatabase('22:59:11')
        );
    }
}
