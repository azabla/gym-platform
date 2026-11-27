<?php

declare(strict_types=1);

namespace App\Insights;

use App\Domains\Organizations\Enums\StaffRole;

/**
 * The insights owners look at (ADR 0040), and who sees each: revenue the
 * roles that see the gym's money, the others owners and managers.
 */
enum Insight: string
{
    case Revenue = 'revenue';
    case Renewals = 'renewals';
    case BusyHours = 'busy-hours';
    case Members = 'members';

    public function label(): string
    {
        return match ($this) {
            self::Revenue => 'Revenue by month',
            self::Renewals => 'Renewals and churn',
            self::BusyHours => 'Busiest hours',
            self::Members => 'Members and visit packs',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Revenue => 'Money taken each month, net of refunds, by plan and by method.',
            self::Renewals => 'Memberships that ended each month: renewed within 14 days, lapsed, or still waiting.',
            self::BusyHours => 'Members let in by weekday and hour, where the gym is.',
            self::Members => 'Members with a valid membership, new members, and how fully visit packs were used.',
        };
    }

    public function isSeenBy(StaffRole $role): bool
    {
        return match ($this) {
            self::Revenue => $role->seesMoney(),
            default => in_array($role, [StaffRole::Owner, StaffRole::Manager], true),
        };
    }
}
