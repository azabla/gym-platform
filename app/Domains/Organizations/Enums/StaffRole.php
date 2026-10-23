<?php

declare(strict_types=1);

namespace App\Domains\Organizations\Enums;

/**
 * A staff member's role at their organization (gym).
 *
 * Add a case only when a feature defines what the role may do; an Accountant
 * role is expected with Billing. See ADR 0003.
 */
enum StaffRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Receptionist = 'receptionist';
    case Trainer = 'trainer';
}
