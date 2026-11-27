<?php

declare(strict_types=1);

namespace App\Domains\Organizations\Exceptions;

use DomainException;

/**
 * Pausing a gym that is already paused, or resuming one that isn't. Only
 * platform admins pause gyms, in the admin panel, which is English, so the
 * messages are not translated. See ADR 0043.
 */
final class GymSuspensionRefused extends DomainException
{
    public static function alreadyPaused(): self
    {
        return new self('This gym is already paused.');
    }

    public static function notPaused(): self
    {
        return new self('This gym isn\'t paused.');
    }

    public static function noReason(): self
    {
        return new self('Say why the gym is paused, so the next admin knows.');
    }
}
