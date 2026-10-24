<?php

declare(strict_types=1);

namespace App\Domains\Attendance\Access;

use App\Domains\Attendance\Enums\CheckInDenial;
use App\Domains\Memberships\Models\Membership;

/**
 * Whether a member may come in: the membership that best shows where they
 * stand today, if any, and why not, when they may not.
 */
final readonly class AccessDecision
{
    public function __construct(
        public ?Membership $membership,
        public ?CheckInDenial $denial,
    ) {}

    public function allows(): bool
    {
        return $this->denial === null;
    }
}
