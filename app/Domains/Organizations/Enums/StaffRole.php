<?php

declare(strict_types=1);

namespace App\Domains\Organizations\Enums;

/**
 * A staff member's role at their organization (gym). An accountant handles the
 * gym's money (payments, refunds and corrections) and otherwise only looks.
 *
 * Add a case only when a feature defines what the role may do. See ADR 0003
 * and ADR 0008.
 */
enum StaffRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Receptionist = 'receptionist';
    case Trainer = 'trainer';
    case Accountant = 'accountant';

    /**
     * Whether the role sees the gym's money on the dashboard: what was taken
     * and what is owed. Receptionists take payments but don't see the totals,
     * and trainers don't handle money. See ADR 0014.
     */
    public function seesMoney(): bool
    {
        return match ($this) {
            self::Owner, self::Manager, self::Accountant => true,
            self::Receptionist, self::Trainer => false,
        };
    }
}
