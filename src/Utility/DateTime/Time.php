<?php
declare(strict_types=1);

namespace Fyre\Utility\DateTime;

use DateMalformedStringException;
use DateTimeImmutable;
use Fyre\Core\Traits\MacroTrait;
use Fyre\Core\Traits\StaticMacroTrait;
use Fyre\Utility\DateTime\Traits\TimeTrait;
use IntlCalendar;
use Override;

use function array_combine;
use function array_pad;
use function str_contains;

/**
 * Represents an immutable time of day.
 *
 * The source time is retained while the time zone is rebased to UTC and the date is clamped to
 * the UNIX epoch date.
 *
 * @phpstan-consistent-constructor
 */
class Time extends AbstractDateTime
{
    use MacroTrait;
    use StaticMacroTrait;
    use TimeTrait;

    #[Override]
    protected static bool $preserveSourceTimeZone = true;

    /**
     * Creates a new Time from an array.
     *
     * @param int[] $timeArray The time to parse as `[hour, minute, second, millisecond]`.
     * @param string|null $timeZone The source time zone.
     * @param string|null $locale The locale to use.
     * @return static The new Time instance.
     */
    public static function createFromArray(array $timeArray, string|null $timeZone = null, string|null $locale = null): static
    {
        $time = new static(null, $timeZone, $locale);

        return array_pad($timeArray, 4, 0)
            |> array_combine(['hour', 'minute', 'second', 'millisecond'], ?)
            |> $time->withCalendarFields(...);
    }

    /**
     * Creates a new Time from an ISO 8601 time string.
     *
     * @param string $dateString The time string.
     * @param string|null $timeZone The source time zone.
     * @param string|null $locale The locale to use.
     * @return static The new Time instance.
     *
     * @throws DateMalformedStringException If the time string is not valid ISO 8601.
     */
    #[Override]
    public static function createFromIsoString(string $dateString, string|null $timeZone = null, string|null $locale = null): static
    {
        $timeZone = static::parseTimeZone($timeZone);
        $format = str_contains($dateString, '.') ? 'H:i:s.v' : 'H:i:s';
        $time = DateTimeImmutable::createFromFormat('!'.$format, $dateString, $timeZone);

        if ($time === false || $time->format($format) !== $dateString) {
            throw new DateMalformedStringException('Time string is not valid ISO 8601.');
        }

        return static::createFromNativeDateTime($time, $timeZone->getName(), $locale);
    }

    /**
     * Returns the difference in milliseconds.
     *
     * @param Time $other The Time to compare to.
     * @return int The difference.
     */
    public function diff(Time $other): int
    {
        return $this->getTime() - $other->getTime();
    }

    /**
     * Checks whether this Time is after another Time.
     *
     * @param Time $other The Time to compare to.
     * @return bool Whether this Time is after the other Time.
     */
    public function isAfter(Time $other): bool
    {
        return $this->diff($other) > 0;
    }

    /**
     * Checks whether this Time is before another Time.
     *
     * @param Time $other The Time to compare to.
     * @return bool Whether this Time is before the other Time.
     */
    public function isBefore(Time $other): bool
    {
        return $this->diff($other) < 0;
    }

    /**
     * Checks whether this Time is between two Times.
     *
     * @param Time $start The start Time.
     * @param Time $end The end Time.
     * @return bool Whether this Time is between the other Times.
     */
    public function isBetween(Time $start, Time $end): bool
    {
        return $this->isAfter($start) && $this->isBefore($end);
    }

    /**
     * Checks whether this Time is the same as another Time.
     *
     * @param Time $other The Time to compare to.
     * @return bool Whether the Times are the same.
     */
    public function isSame(Time $other): bool
    {
        return $this->diff($other) === 0;
    }

    /**
     * Checks whether this Time is the same as or after another Time.
     *
     * @param Time $other The Time to compare to.
     * @return bool Whether this Time is the same as or after the other Time.
     */
    public function isSameOrAfter(Time $other): bool
    {
        return $this->diff($other) >= 0;
    }

    /**
     * Checks whether this Time is the same as or before another Time.
     *
     * @param Time $other The Time to compare to.
     * @return bool Whether this Time is the same as or before the other Time.
     */
    public function isSameOrBefore(Time $other): bool
    {
        return $this->diff($other) <= 0;
    }

    /**
     * Returns the ISO time string.
     *
     * @return string The time string.
     */
    #[Override]
    public function toIsoString(): string
    {
        $format = $this->getMilliseconds() ? 'H:i:s.v' : 'H:i:s';

        return $this->toNativeDateTime()->format($format);
    }

    /**
     * Returns the time string.
     *
     * @return string The time string.
     */
    #[Override]
    public function toString(): string
    {
        return $this->toIsoString();
    }

    /**
     * Calculates a clock difference.
     *
     * @param Time $other The Time to compare to.
     * @param 'hour'|'minute'|'second' $timeUnit The unit of time.
     * @param bool $relative Whether to compare by the clock unit.
     * @return int The difference.
     */
    protected function calculateDiff(Time $other, string $timeUnit, bool $relative = true): int
    {
        $field = static::getAdjustmentField($timeUnit);

        if ($relative) {
            $other = $other->getTime() |> $other->withTimeValue(...);
            $adjust = false;

            foreach (['hour', 'minute', 'second', 'millisecond'] as $unit) {
                $tempField = static::getAdjustmentField($unit);

                if ($adjust) {
                    $other->calendar->set($tempField, $this->calendar->get($tempField));
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
     * Clamps the calendar to the retained time on the UNIX epoch date in UTC.
     */
    #[Override]
    protected function normalizeCalendar(): void
    {
        $hours = $this->calendar->get(IntlCalendar::FIELD_HOUR_OF_DAY);
        $minutes = $this->calendar->get(IntlCalendar::FIELD_MINUTE);
        $seconds = $this->calendar->get(IntlCalendar::FIELD_SECOND);
        $milliseconds = $this->calendar->get(IntlCalendar::FIELD_MILLISECOND);

        $this->calendar->setTimeZone('UTC');
        $this->calendar->setTime(0);
        $this->calendar->set(IntlCalendar::FIELD_HOUR_OF_DAY, $hours);
        $this->calendar->set(IntlCalendar::FIELD_MINUTE, $minutes);
        $this->calendar->set(IntlCalendar::FIELD_SECOND, $seconds);
        $this->calendar->set(IntlCalendar::FIELD_MILLISECOND, $milliseconds);
    }
}
