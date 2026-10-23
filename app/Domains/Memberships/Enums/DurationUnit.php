<?php

declare(strict_types=1);

namespace App\Domains\Memberships\Enums;

/**
 * How a plan's length is counted: in days (a Day Pass, or a fixed 30-day month)
 * or in calendar months (Monthly, 3 Months, or 12 months for a year). See ADR 0006.
 */
enum DurationUnit: string
{
    case Day = 'day';
    case Month = 'month';
}
