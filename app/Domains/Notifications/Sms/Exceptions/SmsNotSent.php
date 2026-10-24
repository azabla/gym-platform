<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Sms\Exceptions;

use RuntimeException;

/**
 * The SMS provider refused a message or didn't answer, so it wasn't sent.
 */
final class SmsNotSent extends RuntimeException
{
    public static function refused(string $reason): self
    {
        return new self("The SMS provider refused the message: {$reason}");
    }

    public static function unavailable(): self
    {
        return new self('The SMS provider didn\'t answer.');
    }
}
