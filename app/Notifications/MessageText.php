<?php

namespace App\Notifications;

use App\Domains\Members\Models\Member;
use App\Domains\Shared\Dates\ReadableDate;
use Carbon\CarbonImmutable;

/**
 * Writes a message to a member in a language, from its type and details, the
 * same way for the inbox, the push and the SMS. A detail given as
 * ['translate' => key] is itself translated, such as a check-in's reason, and
 * a "count" detail picks the singular or plural form. See ADR 0012.
 */
final class MessageText
{
    /** Details kept as a date ("2026-11-27") and written out for the reader. */
    private const DATES = ['starts_on', 'ends_on', 'membership_ends_on', 'due_on', 'pauses_on'];

    /**
     * "27 November 2026" in English; in Amharic the Ethiopian date, with the
     * Gregorian beside it except in an SMS, which stays short (ADR 0041).
     */
    private static function dateIn(string $date, string $language, bool $forSms): string
    {
        $day = CarbonImmutable::parse($date);

        return $forSms ? ReadableDate::ethiopian($day, $language) : ReadableDate::long($day, $language);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function title(string $type, array $details, string $language): string
    {
        return self::write("notifications.{$type}.title", $details, $language);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function body(string $type, array $details, string $language): string
    {
        return self::write("notifications.{$type}.body", $details, $language);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function sms(string $type, array $details, string $language): string
    {
        return self::write("notifications.{$type}.sms", $details, $language, forSms: true);
    }

    /**
     * An SMS to a member, in their message language: their own, else their
     * gym's (ADR 0034). Anyone else gets the application's language.
     *
     * @param  array<string, mixed>  $details
     */
    public static function smsTo(object $notifiable, string $type, array $details): string
    {
        $language = $notifiable instanceof Member
            ? $notifiable->messageLanguage()->value
            : (string) config('app.fallback_locale');

        return self::sms($type, $details, $language);
    }

    /**
     * A message to staff, from lang/{language}/staff_notifications.php
     * (ADR 0027), in the staff member's message language (ADR 0034).
     *
     * @param  array<string, mixed>  $details
     */
    public static function forStaff(string $type, string $part, array $details, string $language = 'en'): string
    {
        return self::write("staff_notifications.{$type}.{$part}", $details, $language, forSms: $part === 'sms');
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private static function write(string $key, array $details, string $language, bool $forSms = false): string
    {
        $replace = [];

        foreach ($details as $name => $value) {
            $replace[$name] = match (true) {
                is_array($value) && is_string($value['translate'] ?? null) => (string) __($value['translate'], [], $language),
                in_array($name, self::DATES, true) && is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 => self::dateIn($value, $language, $forSms),
                default => is_scalar($value) ? (string) $value : '',
            };
        }

        return isset($details['count']) && is_int($details['count'])
            ? trans_choice($key, $details['count'], $replace, $language)
            : (string) __($key, $replace, $language);
    }
}
