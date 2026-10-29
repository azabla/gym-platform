<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\Concerns;

use App\Domains\Organizations\Tenancy\CurrentOrganization;

/**
 * Times are stored in UTC; the panel shows them where the gym is, so a payment
 * voided at 22:30 UTC in Addis Ababa shows the next day, 01:30. See ADR 0017.
 */
trait ShowsGymTime
{
    protected static function gymTimezone(): string
    {
        return app(CurrentOrganization::class)->get()->timezone;
    }
}
