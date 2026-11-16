<?php

declare(strict_types=1);

namespace App\Domains\Memberships\Events;

use App\Domains\Memberships\Models\Membership;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A freeze of the membership ended early, by staff or by the member in the
 * app; the membership's end date has moved back (ADR 0007 and ADR 0026). The
 * freeze itself may be gone, if it hadn't started yet.
 */
final class MembershipFreezeEnded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Membership $membership,
        public readonly bool $endedInTheApp,
    ) {}
}
