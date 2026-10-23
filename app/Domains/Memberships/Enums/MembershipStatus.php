<?php

declare(strict_types=1);

namespace App\Domains\Memberships\Enums;

/**
 * Where a membership stands on a given day. It is worked out from the dates
 * and the cancellation each time, never stored, so nothing has to run at
 * midnight. See ADR 0006.
 */
enum MembershipStatus: string
{
    case Upcoming = 'upcoming';
    case Active = 'active';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
