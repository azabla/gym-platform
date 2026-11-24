<?php

declare(strict_types=1);

namespace App\Domains\Shared\EthiopianCalendar;

use InvalidArgumentException;

/**
 * A date in the Ethiopian calendar: 12 months of 30 days and Pagume, of 5
 * days or 6 in a leap year (a year that leaves 3 when divided by 4).
 * Converted through day numbers (Julian day numbers), so a holiday on a fixed
 * Ethiopian date, such as Timket on Tir 11, lands on the right Gregorian day
 * every year. See ADR 0039.
 */
final readonly class EthiopianDate
{
    /** The day number of Meskerem 1 of year 1 (Amete Mihret). */
    private const EPOCH = 1724221;

    private const MONTHS = [
        1 => 'Meskerem', 2 => 'Tikimt', 3 => 'Hidar', 4 => 'Tahsas', 5 => 'Tir', 6 => 'Yekatit',
        7 => 'Megabit', 8 => 'Miyazya', 9 => 'Ginbot', 10 => 'Sene', 11 => 'Hamle', 12 => 'Nehase', 13 => 'Pagume',
    ];

    private function __construct(
        public int $year,
        public int $month,
        public int $day,
    ) {}

    /**
     * @throws InvalidArgumentException for a day the month doesn't have
     */
    public static function of(int $year, int $month, int $day): self
    {
        $daysInMonth = $month === 13 ? (self::isLeapYear($year) ? 6 : 5) : 30;

        if ($month < 1 || $month > 13 || $day < 1 || $day > $daysInMonth) {
            throw new InvalidArgumentException("There is no day {$day} of month {$month} in {$year}.");
        }

        return new self($year, $month, $day);
    }

    /**
     * The Ethiopian date of a Gregorian date given as "YYYY-MM-DD".
     */
    public static function fromGregorian(string $date): self
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new InvalidArgumentException("{$date} isn't a date written as YYYY-MM-DD.");
        }

        $offset = self::gregorianDayNumber((int) $parts[1], (int) $parts[2], (int) $parts[3]) - self::EPOCH;
        $year = intdiv($offset * 4, 1461) + 1;

        while (self::startOfYear($year) > $offset) {
            $year--;
        }

        while (self::startOfYear($year + 1) <= $offset) {
            $year++;
        }

        $dayOfYear = $offset - self::startOfYear($year);

        return new self($year, intdiv($dayOfYear, 30) + 1, $dayOfYear % 30 + 1);
    }

    /**
     * Whether the year has a 6th of Pagume.
     */
    public static function isLeapYear(int $year): bool
    {
        return $year % 4 === 3;
    }

    /**
     * The Gregorian date, as "YYYY-MM-DD".
     */
    public function toGregorian(): string
    {
        $dayNumber = self::EPOCH + self::startOfYear($this->year) + 30 * ($this->month - 1) + $this->day - 1;

        $a = $dayNumber + 32044;
        $b = intdiv(4 * $a + 3, 146097);
        $c = $a - intdiv(146097 * $b, 4);
        $d = intdiv(4 * $c + 3, 1461);
        $e = $c - intdiv(1461 * $d, 4);
        $m = intdiv(5 * $e + 2, 153);

        $day = $e - intdiv(153 * $m + 2, 5) + 1;
        $month = $m + 3 - 12 * intdiv($m, 10);
        $year = 100 * $b + $d - 4800 + intdiv($m, 10);

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * The same day and month the given number of years later; Pagume 6
     * becomes Pagume 5 in a year without it.
     */
    public function addYears(int $years): self
    {
        $year = $this->year + $years;
        $day = $this->month === 13 && $this->day === 6 && ! self::isLeapYear($year) ? 5 : $this->day;

        return new self($year, $this->month, $day);
    }

    public function monthName(): string
    {
        return self::MONTHS[$this->month];
    }

    /**
     * Such as "Tir 11".
     */
    public function dayAndMonth(): string
    {
        return $this->monthName().' '.$this->day;
    }

    /**
     * Days from the epoch to Meskerem 1 of the year.
     */
    private static function startOfYear(int $year): int
    {
        return 365 * ($year - 1) + intdiv($year, 4);
    }

    private static function gregorianDayNumber(int $year, int $month, int $day): int
    {
        $a = intdiv(14 - $month, 12);
        $y = $year + 4800 - $a;
        $m = $month + 12 * $a - 3;

        return $day + intdiv(153 * $m + 2, 5) + 365 * $y + intdiv($y, 4) - intdiv($y, 100) + intdiv($y, 400) - 32045;
    }
}
