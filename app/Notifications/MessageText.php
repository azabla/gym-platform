<?php

namespace App\Notifications;

/**
 * Writes a message to a member in a language, from its type and details, the
 * same way for the inbox, the push and the SMS. A detail given as
 * ['translate' => key] is itself translated, such as a check-in's reason, and
 * a "count" detail picks the singular or plural form. See ADR 0012.
 */
final class MessageText
{
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
        return self::write("notifications.{$type}.sms", $details, $language);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private static function write(string $key, array $details, string $language): string
    {
        $replace = [];

        foreach ($details as $name => $value) {
            $replace[$name] = is_array($value) && is_string($value['translate'] ?? null)
                ? (string) __($value['translate'], [], $language)
                : (is_scalar($value) ? (string) $value : '');
        }

        return isset($details['count']) && is_int($details['count'])
            ? trans_choice($key, $details['count'], $replace, $language)
            : (string) __($key, $replace, $language);
    }
}
