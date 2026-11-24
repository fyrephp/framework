<?php
declare(strict_types=1);

namespace Fyre\Utility\DateTime;

use DateTimeInterface;
use DateTimeZone;
use Fyre\Core\Traits\MacroTrait;
use Fyre\Core\Traits\StaticMacroTrait;
use Fyre\Utility\DateTime\Traits\DateTrait;
use Fyre\Utility\DateTime\Traits\TimeTrait;
use IntlCalendar;
use Override;

use function abs;
use function array_combine;
use function array_pad;
use function floor;
use function str_pad;
use function strtolower;

use const STR_PAD_LEFT;

/**
 * Represents an immutable date and time with locale-aware formatting.
 *
 * @phpstan-consistent-constructor
 */
class DateTime extends AbstractDateTime
{
    use DateTrait;
    use MacroTrait;
    use StaticMacroTrait;
    use TimeTrait;

    /**
     * Creates a new DateTime from an array.
     *
     * @param int[] $dateArray The date to parse as `[year, month, day, hour, minute, second, millisecond]`.
     * @param string|null $timeZone The time zone to use.
     * @param string|null $locale The locale to use.
     * @return static The new DateTime instance.
     */
    public static function createFromArray(array $dateArray, string|null $timeZone = null, string|null $locale = null): static
    {
        $dateTime = new static(null, $timeZone, $locale);

        $dateArray = array_pad($dateArray, 3, 1) |> array_pad(?, 7, 0);

        $keys = ['year', 'month', 'date', 'hour', 'minute', 'second', 'millisecond'];
        $dateArray[1]--;

        return array_combine($keys, $dateArray) |> $dateTime->withCalendarFields(...);
    }

    /**
     * Returns the difference between this and another DateTime in milliseconds.
     *
     * @param DateTime $other The DateTime to compare to.
     * @return int The difference in milliseconds.
     */
    public function diff(DateTime $other): int
    {
        return $this->getTime() - $other->getTime();
    }

    /**
     * Sets the DateTime to the end of the day.
     *
     * @return static The new DateTime instance with the time set to the end of the day.
     */
    public function endOfDay(): static
    {
        return $this->withHours(23, 59, 59, 999);
    }

    /**
     * Sets the DateTime to the end of the month.
     *
     * @return static The new DateTime instance with the date set to the end of the month.
     */
    public function endOfMonth(): static
    {
        $dateTime = $this->daysInMonth() |> $this->withDate(...);

        return $dateTime->endOfDay();
    }

    /**
     * Sets the DateTime to the end of the quarter.
     *
     * @return static The new DateTime instance with the date set to the end of the quarter.
     */
    public function endOfQuarter(): static
    {
        $month = $this->getQuarter() * 3;

        return $this->withMonth(
            $month,
            static::createFromArray([$this->getYear(), $month])->daysInMonth()
        )->endOfDay();
    }

    /**
     * Sets the DateTime to the end of the week.
     *
     * @return static The new DateTime instance with the date set to the end of the week.
     */
    public function endOfWeek(): static
    {
        return $this->withWeekDay(7)
            ->endOfDay();
    }

    /**
     * Sets the DateTime to the end of the year.
     *
     * @return static The new DateTime instance with the date set to the end of the year.
     */
    public function endOfYear(): static
    {
        return $this->withMonth(12, 31)
            ->endOfDay();
    }

    /**
     * Returns the UTC offset (in minutes) of the current time zone.
     *
     * Note: This uses the inverse sign convention of {@see DateTimeZone::getOffset()} so it can
     * be round-tripped with {@see DateTime::withTimeZoneOffset()}. For example, a `+10:00`
     * timezone returns `-600`.
     *
     * @return int The UTC offset (in minutes) of the current time zone.
     */
    public function getTimeZoneOffset(): int
    {
        return (int) ($this->toNativeDateTime()->getOffset() / 60 * -1);
    }

    /**
     * Checks whether this DateTime is after another date.
     *
     * @param DateTime $other The DateTime to compare to.
     * @return bool Whether this DateTime is after the other date.
     */
    public function isAfter(DateTime $other): bool
    {
        return $this->diff($other) > 0;
    }

    /**
     * Checks whether this DateTime is before another date.
     *
     * @param DateTime $other The DateTime to compare to.
     * @return bool Whether this DateTime is before the other date.
     */
    public function isBefore(DateTime $other): bool
    {
        return $this->diff($other) < 0;
    }

    /**
     * Checks whether this DateTime is between two other dates.
     *
     * @param DateTime $start The DateTime representing the start boundary.
     * @param DateTime $end The DateTime representing the end boundary.
     * @return bool Whether this DateTime is between the other dates.
     */
    public function isBetween(DateTime $start, DateTime $end): bool
    {
        return $this->isAfter($start) && $this->isBefore($end);
    }

    /**
     * Checks whether the DateTime is in daylight savings.
     *
     * @return bool Whether the current time is in daylight savings.
     */
    public function isDst(): bool
    {
        return (bool) $this->toNativeDateTime()->format('I');
    }

    /**
     * Checks whether this DateTime is the same as another date.
     *
     * @param DateTime $other The DateTime to compare to.
     * @return bool Whether this DateTime is the same as the other date.
     */
    public function isSame(DateTime $other): bool
    {
        return $this->diff($other) === 0;
    }

    /**
     * Checks whether this DateTime is the same as or after another date.
     *
     * @param DateTime $other The DateTime to compare to.
     * @return bool Whether this DateTime is the same as or after the other date.
     */
    public function isSameOrAfter(DateTime $other): bool
    {
        return $this->diff($other) >= 0;
    }

    /**
     * Checks whether this DateTime is the same as or before another date.
     *
     * @param DateTime $other The DateTime to compare to.
     * @return bool Whether this DateTime is the same as or before the other date.
     */
    public function isSameOrBefore(DateTime $other): bool
    {
        return $this->diff($other) <= 0;
    }

    /**
     * Sets the DateTime to the start of the day.
     *
     * @return static The new DateTime instance with the time set to the start of the day.
     */
    public function startOfDay(): static
    {
        return $this->withHours(0, 0, 0, 0);
    }

    /**
     * Sets the DateTime to the start of the month.
     *
     * @return static The new DateTime instance with the date set to the start of the month.
     */
    public function startOfMonth(): static
    {
        return $this->withDate(1)
            ->startOfDay();
    }

    /**
     * Sets the DateTime to the start of the quarter.
     *
     * @return static The new DateTime instance with the date set to the start of the quarter.
     */
    public function startOfQuarter(): static
    {
        $month = $this->getQuarter() * 3 - 2;

        return $this->withMonth($month, 1)
            ->startOfDay();
    }

    /**
     * Sets the DateTime to the start of the week.
     *
     * @return static The new DateTime instance with the date set to the start of the week.
     */
    public function startOfWeek(): static
    {
        return $this->withWeekDay(1)
            ->startOfDay();
    }

    /**
     * Sets the DateTime to the start of the year.
     *
     * @return static The new DateTime instance with the date set to the start of the year.
     */
    public function startOfYear(): static
    {
        return $this->withMonth(1, 1)
            ->startOfDay();
    }

    /**
     * Returns the name of the current time zone.
     *
     * @param string $type The formatting type.
     * @return string|null The name of the time zone.
     */
    public function timeZoneName(string $type = 'full'): string|null
    {
        $type = strtolower($type);

        return match ($type) {
            'short' => $this->format('zzz'),
            'full' => $this->format('zzzz'),
            default => null
        };
    }

    /**
     * Formats the current date using "eee MMM dd yyyy".
     *
     * @return string The formatted date string.
     */
    public function toDateString(): string
    {
        return $this->format('eee MMM dd yyyy');
    }

    /**
     * Formats the current date as an ISO 8601 / RFC3339 string in UTC.
     *
     * @return string The formatted date string.
     */
    #[Override]
    public function toIsoString(): string
    {
        return $this
            ->withTimeZone('UTC')
            ->toNativeDateTime()
            ->format(DateTimeInterface::RFC3339_EXTENDED);
    }

    /**
     * Formats the current date using "eee MMM dd yyyy HH:mm:ss xx (VV)".
     *
     * @return string The formatted date string.
     */
    #[Override]
    public function toString(): string
    {
        return $this->format('eee MMM dd yyyy HH:mm:ss xx (VV)');
    }

    /**
     * Formats the current date using "HH:mm:ss xx (VV)".
     *
     * @return string The formatted date string.
     */
    public function toTimeString(): string
    {
        return $this->format('HH:mm:ss xx (VV)');
    }

    /**
     * Formats the current date in UTC timeZone using "eee MMM dd yyyy HH:mm:ss xx (VV)".
     *
     * @return string The formatted date string.
     */
    public function toUTCString(): string
    {
        return $this
            ->withTimeZone('UTC')
            ->toString();
    }

    /**
     * Returns the new DateTime instance with the updated number of milliseconds since the UNIX epoch.
     *
     * @param int $time The number of milliseconds since the UNIX epoch.
     * @return static The new DateTime instance with the updated timestamp.
     */
    public function withTime(int $time): static
    {
        return $this->withTimeValue($time);
    }

    /**
     * Returns the new DateTime instance with the updated number of seconds since the UNIX epoch.
     *
     * @param int $timestamp The number of seconds since the UNIX epoch.
     * @return static The new DateTime instance with the updated timestamp.
     */
    public function withTimestamp(int $timestamp): static
    {
        return $this->withTime($timestamp * 1000);
    }

    /**
     * Returns the new DateTime instance with the updated time zone.
     *
     * @param string $timeZone The name of the time zone.
     * @return static The new DateTime instance with the updated time zone.
     */
    public function withTimeZone(string $timeZone): static
    {
        $temp = new static(null, $timeZone, $this->locale);

        $this->getTime() |> $temp->calendar->setTime(...);

        return $temp;
    }

    /**
     * Returns the new DateTime instance with the updated UTC offset.
     *
     * Note: The offset uses the same sign convention as {@see DateTime::getTimeZoneOffset()}:
     * negative values indicate timezones ahead of UTC (e.g. `-600` -> `+10:00`).
     *
     * @param int $offset The UTC offset (in minutes).
     * @return static The new DateTime instance with the updated UTC offset.
     */
    public function withTimeZoneOffset(int $offset): static
    {
        $offset *= -1;
        $prefix = $offset >= 0 ? '+' : '-';
        $offset = abs($offset);

        $timeZone = $prefix.
            str_pad((string) floor($offset / 60), 2, '0', STR_PAD_LEFT).
            ':'.
            str_pad((string) ($offset % 60), 2, '0', STR_PAD_LEFT);

        return $this->withTimeZone($timeZone);
    }

    /**
     * Calculates the difference between this and another DateTime.
     *
     * @param DateTime $other The DateTime to compare to.
     * @param 'day'|'hour'|'millisecond'|'minute'|'month'|'second'|'week'|'year' $timeUnit The unit of time.
     * @param bool $relative Whether to use the relative difference.
     * @return int The difference.
     */
    protected function calculateDiff(DateTime $other, string $timeUnit, bool $relative = true): int
    {
        $field = static::getAdjustmentField($timeUnit);

        if ($relative) {
            $other = $this->getTimeZone() |> $other->withTimeZone(...);
            $adjust = false;

            foreach (['year', 'month', 'week', 'day', 'hour', 'minute', 'second', 'millisecond'] as $timeUnit) {
                $tempField = static::getAdjustmentField($timeUnit);

                if ($field === IntlCalendar::FIELD_WEEK_OF_YEAR && $tempField === IntlCalendar::FIELD_DATE) {
                    $tempField = IntlCalendar::FIELD_DAY_OF_WEEK;
                }

                if ($adjust) {
                    $value = $this->calendar->get($tempField);
                    $other->calendar->set($tempField, $value);
                }

                if ($tempField === $field) {
                    $adjust = true;
                }
            }
        }

        $calendar = clone $this->calendar;

        return $calendar->fieldDifference($other->getTime(), $field) * -1;
    }

    /**
     * Normalizes the calendar for the concrete value type.
     */
    #[Override]
    protected function normalizeCalendar(): void {}
}
