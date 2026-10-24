<?php

declare(strict_types=1);

namespace App\Domains\Attendance\Credentials;

/**
 * GQ1, the rotating QR code a member shows at the door, agreed with the
 * members app (the Volt contract). Each code is an HMAC-SHA256 of the
 * credential's ULID and the current 30-second step, keyed with a day key
 * derived from the credential's secret, so a code works for about a minute
 * and the phone makes it offline. See ADR 0011.
 *
 * day_key = HMAC(secret, "GQ1-DAY|" + day)
 * code    = first 8 bytes of HMAC(day_key, "GQ1|" + credential_id + "|" + step), uppercase hex
 * payload = "GQ1:" + credential_id + ":" + step + ":" + code
 */
final class Gq1
{
    public const STEP_SECONDS = 30;

    private const STEPS_PER_DAY = 2880;

    private const PAYLOAD = '/^GQ1:([0-9A-HJKMNP-TV-Z]{26}):(\d{1,12}):([0-9A-F]{16})$/';

    /**
     * The 30-second step a moment falls in.
     */
    public static function stepAt(int $unixSeconds): int
    {
        return intdiv($unixSeconds, self::STEP_SECONDS);
    }

    /**
     * The key for one UTC day, derived from the credential's raw 32-byte secret.
     */
    public static function dayKey(string $secret, int $day): string
    {
        return hash_hmac('sha256', "GQ1-DAY|{$day}", $secret, true);
    }

    /**
     * The 16-character code for the credential (its ULID in uppercase) at the step.
     */
    public static function code(string $secret, string $credentialId, int $step): string
    {
        $mac = hash_hmac('sha256', "GQ1|{$credentialId}|{$step}", self::dayKey($secret, intdiv($step, self::STEPS_PER_DAY)), true);

        return strtoupper(bin2hex(substr($mac, 0, 8)));
    }

    /**
     * What the QR code holds at the step.
     */
    public static function payload(string $secret, string $credentialId, int $step): string
    {
        return "GQ1:{$credentialId}:{$step}:".self::code($secret, $credentialId, $step);
    }

    /**
     * Read a scanned QR code, or null when it isn't a GQ1 payload.
     */
    public static function parse(string $payload): ?Gq1Payload
    {
        if (preg_match(self::PAYLOAD, trim($payload), $matches) !== 1) {
            return null;
        }

        return new Gq1Payload(credentialId: $matches[1], step: (int) $matches[2], code: $matches[3]);
    }
}
