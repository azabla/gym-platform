<?php

declare(strict_types=1);

namespace App\Filament\Staff\Support;

use App\Domains\Shared\Dates\ReadableDate;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;

/**
 * Dates in the staff panel, in the staff member's language: the Ethiopian
 * date with the Gregorian beside it in Amharic, the Gregorian date in
 * English (ADR 0041). Moments (created_at and the like) are shown in the
 * gym's time zone; dates (starts_on and the like) as they are.
 */
final class Dates
{
    public static function short(CarbonInterface|string $date): string
    {
        return ReadableDate::short(self::parse($date), app()->getLocale());
    }

    public static function long(CarbonInterface|string $date): string
    {
        return ReadableDate::long(self::parse($date), app()->getLocale());
    }

    public static function dayOfWeek(CarbonInterface|string $date): string
    {
        return ReadableDate::dayOfWeek(self::parse($date), app()->getLocale());
    }

    public static function month(CarbonInterface|string $date): string
    {
        return ReadableDate::month(self::parse($date), app()->getLocale());
    }

    public static function weekday(int $isoWeekday): string
    {
        return ReadableDate::weekday($isoWeekday, app()->getLocale());
    }

    /**
     * For a column or entry's formatStateUsing(): the date, or the moment's
     * date where the gym is when a time zone is given.
     *
     * @return Closure(mixed): string
     */
    public static function asDate(?string $timezone = null): Closure
    {
        return fn (mixed $state): string => self::short(self::inZone($state, $timezone));
    }

    /**
     * For a column or entry's formatStateUsing(): the moment's date and time
     * where the gym is.
     *
     * @return Closure(mixed): string
     */
    public static function asDateTime(string $timezone): Closure
    {
        return fn (mixed $state): string => ReadableDate::shortWithTime(self::inZone($state, $timezone), app()->getLocale());
    }

    /**
     * For a date picker's hint(): the Ethiopian date of the day chosen, for
     * staff reading Amharic, as the picker itself is Gregorian.
     *
     * @return Closure(mixed): ?string
     */
    public static function ethiopianHint(): Closure
    {
        return fn (mixed $state): ?string => app()->getLocale() === 'am' && is_string($state) && $state !== ''
            ? ReadableDate::ethiopian(CarbonImmutable::parse($state), 'am')
            : null;
    }

    private static function inZone(mixed $state, ?string $timezone): CarbonImmutable
    {
        $date = $state instanceof CarbonInterface ? CarbonImmutable::instance($state) : CarbonImmutable::parse(is_scalar($state) ? (string) $state : 'now');

        return $timezone === null ? $date : $date->setTimezone($timezone);
    }

    private static function parse(CarbonInterface|string $date): CarbonImmutable
    {
        return $date instanceof CarbonInterface ? CarbonImmutable::instance($date) : CarbonImmutable::parse($date);
    }
}
