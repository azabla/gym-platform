<?php

declare(strict_types=1);

namespace App\Domains\Organizations\Exceptions;

use DomainException;

/**
 * Anything done at a gym the platform has paused: the staff panel, the
 * staff API and the members app refuse it with this, in the reader's
 * language, and the APIs answer 403 with the code GYM_PAUSED. See ADR 0043.
 */
final class GymSuspended extends DomainException
{
    public const CODE = 'GYM_PAUSED';

    /**
     * For the gym's staff, who deal with the platform.
     */
    public static function make(): self
    {
        return new self(__('This gym\'s account is paused for now. Please contact the platform\'s admins.'));
    }

    /**
     * For the gym's members, who deal with the gym.
     */
    public static function forMembers(): self
    {
        return new self(__('This gym isn\'t available in the app for now. Please ask at the gym.'));
    }
}
