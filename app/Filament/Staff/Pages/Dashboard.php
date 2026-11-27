<?php

declare(strict_types=1);

namespace App\Filament\Staff\Pages;

use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Filament\Staff\Support\Dates;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;
use Filament\Widgets\WidgetConfiguration;

/**
 * The gym at a glance, as the API's dashboard shows it, for a period (today by
 * default) and one of the staff member's branches or all of them. See ADR 0014
 * and ADR 0017.
 */
class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    /**
     * The panel's widgets, without the Insights page's (ADR 0040).
     *
     * @return array<class-string|WidgetConfiguration>
     */
    public function getWidgets(): array
    {
        return array_values(array_filter(
            parent::getWidgets(),
            fn (string|WidgetConfiguration $widget): bool => ! str_starts_with(is_string($widget) ? $widget : $widget->widget, 'App\\Filament\\Staff\\Widgets\\Insights\\'),
        ));
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('from')->hint(Dates::ethiopianHint())->live()->label(__('From'))
                ->default(fn (): string => app(CurrentOrganization::class)->get()->today()->toDateString()),
            DatePicker::make('to')->hint(Dates::ethiopianHint())->live()->label(__('To'))
                ->placeholder(__('Same day')),
            Select::make('branch_id')
                ->label(__('Branch'))
                ->placeholder(__('All my branches'))
                ->options(fn (): array => app(CurrentStaff::class)->get()->branchesWorkedAt()->pluck('name', 'id')->all()),
        ]);
    }
}
