<?php

declare(strict_types=1);

namespace App\Filament\Staff\Pages;

use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;

/**
 * The gym at a glance, as the API's dashboard shows it, for a period (today by
 * default) and one of the staff member's branches or all of them. See ADR 0014
 * and ADR 0017.
 */
class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('from')
                ->default(fn (): string => app(CurrentOrganization::class)->get()->today()->toDateString()),
            DatePicker::make('to')
                ->placeholder('Same day'),
            Select::make('branch_id')
                ->label('Branch')
                ->placeholder('All my branches')
                ->options(fn (): array => app(CurrentStaff::class)->get()->branchesWorkedAt()->pluck('name', 'id')->all()),
        ]);
    }
}
