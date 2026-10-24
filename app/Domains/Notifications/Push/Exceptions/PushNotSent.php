<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Push\Exceptions;

use RuntimeException;

/**
 * The push service couldn't be reached, or refused the push for a reason other
 * than the phone's token no longer working.
 */
final class PushNotSent extends RuntimeException
{
    public static function unavailable(): self
    {
        return new self('The push service is not answering. Try again later.');
    }

    public static function refused(string $reason): self
    {
        return new self("The push service refused the push: {$reason}");
    }

    public static function misconfigured(string $reason): self
    {
        return new self("Push is not set up: {$reason}");
    }
}
