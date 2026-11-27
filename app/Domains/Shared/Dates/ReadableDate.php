<?php

declare(strict_types=1);

namespace App\Domains\Shared\Dates;

use App\Domains\Shared\EthiopianCalendar\EthiopianDate;
use Carbon\CarbonInterface;

/**
 * Dates as people read them, in English or Amharic. Amharic gives the
 * Ethiopian date first with the Gregorian beside it, as the glossary sets
 * (docs/amharic-glossary.md); English keeps the Gregorian date alone. The
 * date is taken as it is given: convert it to the gym's time zone first.
 * See ADR 0041.
 */
final class ReadableDate
{
    private const AMHARIC_GREGORIAN_MONTHS = [
        1 => 'ጃንዋሪ', 2 => 'ፌብሩዋሪ', 3 => 'ማርች', 4 => 'ኤፕሪል', 5 => 'ሜይ', 6 => 'ጁን',
        7 => 'ጁላይ', 8 => 'ኦገስት', 9 => 'ሴፕቴምበር', 10 => 'ኦክቶበር', 11 => 'ኖቬምበር', 12 => 'ዲሴምበር',
    ];

    private const AMHARIC_WEEKDAYS = [1 => 'ሰኞ', 2 => 'ማክሰኞ', 3 => 'ረቡዕ', 4 => 'ሐሙስ', 5 => 'ዓርብ', 6 => 'ቅዳሜ', 7 => 'እሁድ'];

    /**
     * "ኅዳር 18 ቀን 2019 ዓ.ም. (ኖቬምበር 27, 2026)", or "27 November 2026".
     */
    public static function long(CarbonInterface $date, string $language): string
    {
        if ($language !== 'am') {
            return $date->format('j F Y');
        }

        return self::ethiopian($date, $language).' ('.self::AMHARIC_GREGORIAN_MONTHS[$date->month]." {$date->day}, {$date->year})";
    }

    /**
     * "ኅዳር 18, 2019 (27/11/2026)", or "27 Nov 2026", for tables.
     */
    public static function short(CarbonInterface $date, string $language): string
    {
        if ($language !== 'am') {
            return $date->format('j M Y');
        }

        $ethiopian = self::ethiopianDateOf($date);

        return "{$ethiopian->monthNameIn('am')} {$ethiopian->day}, {$ethiopian->year} ({$date->format('d/m/Y')})";
    }

    /**
     * "ኅዳር 18, 2019 (27/11/2026), 14:05", or "27 Nov 2026, 14:05".
     */
    public static function shortWithTime(CarbonInterface $dateTime, string $language): string
    {
        return self::short($dateTime, $language).', '.$dateTime->format('H:i');
    }

    /**
     * "ዓርብ፣ ኅዳር 18 (27/11)", or "Fri 27 Nov", for days close at hand.
     */
    public static function dayOfWeek(CarbonInterface $date, string $language): string
    {
        if ($language !== 'am') {
            return $date->format('D j M');
        }

        $ethiopian = self::ethiopianDateOf($date);

        return self::AMHARIC_WEEKDAYS[$date->dayOfWeekIso]."፣ {$ethiopian->dayAndMonthIn('am')} ({$date->format('d/m')})";
    }

    /**
     * The Ethiopian date alone, "ኅዳር 18 ቀን 2019 ዓ.ም.", for SMS and beside a
     * date picker; "27 November 2026" in English.
     */
    public static function ethiopian(CarbonInterface $date, string $language): string
    {
        if ($language !== 'am') {
            return $date->format('j F Y');
        }

        $ethiopian = self::ethiopianDateOf($date);

        return "{$ethiopian->monthNameIn('am')} {$ethiopian->day} ቀን {$ethiopian->year} ዓ.ም.";
    }

    /**
     * A Gregorian month, "ኖቬምበር 2026" or "Nov 2026", for figures grouped by
     * calendar month.
     */
    public static function month(CarbonInterface $date, string $language): string
    {
        return $language === 'am' ? self::AMHARIC_GREGORIAN_MONTHS[$date->month]." {$date->year}" : $date->format('M Y');
    }

    /**
     * A weekday's name, Monday being 1: "ሰኞ" or "Monday".
     */
    public static function weekday(int $isoWeekday, string $language): string
    {
        return $language === 'am'
            ? self::AMHARIC_WEEKDAYS[$isoWeekday]
            : ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'][$isoWeekday];
    }

    private static function ethiopianDateOf(CarbonInterface $date): EthiopianDate
    {
        return EthiopianDate::fromGregorian($date->format('Y-m-d'));
    }
}
