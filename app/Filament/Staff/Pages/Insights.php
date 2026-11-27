<?php

declare(strict_types=1);

namespace App\Filament\Staff\Pages;

use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Filament\Staff\Actions\DownloadInsightAction;
use App\Filament\Staff\NavigationGroup;
use App\Filament\Staff\Widgets\Insights\BusyHoursByWeekdayChart;
use App\Filament\Staff\Widgets\Insights\BusyHoursGrid;
use App\Filament\Staff\Widgets\Insights\MembersChart;
use App\Filament\Staff\Widgets\Insights\MembersTable;
use App\Filament\Staff\Widgets\Insights\RenewalsChart;
use App\Filament\Staff\Widgets\Insights\RenewalsTable;
use App\Filament\Staff\Widgets\Insights\RevenueChart;
use App\Filament\Staff\Widgets\Insights\RevenueTable;
use App\Insights\Insight;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\WidgetConfiguration;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * Month-by-month insights for owners: revenue, renewals and churn, the
 * busiest hours, and members and visit packs, each a chart with its figures
 * beneath, for the last 3, 6 or 12 months at one of the staff member's
 * branches or all of theirs. Each shows only to the roles it is for. See
 * ADR 0040.
 */
class Insights extends BaseDashboard
{
    use HasFiltersForm;

    protected static string $routePath = 'insights';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Reports;

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Insights';

    protected ?string $subheading = 'How the gym is doing month by month. Download any insight as a CSV file from its table.';

    public static function canAccess(): bool
    {
        return collect(Insight::cases())->contains(fn (Insight $insight): bool => Gate::allows('see-insight', $insight));
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('months')
                ->label('Period')
                ->options(DownloadInsightAction::periods())
                ->default(12)
                ->selectablePlaceholder(false),
            Select::make('branch_id')
                ->label('Branch')
                ->placeholder('All my branches')
                ->options(fn (): array => app(CurrentStaff::class)->get()->branchesWorkedAt()->pluck('name', 'id')->all()),
        ]);
    }

    /**
     * @return array<class-string|WidgetConfiguration>
     */
    public function getWidgets(): array
    {
        return [
            RevenueChart::class,
            RevenueTable::class,
            RenewalsChart::class,
            RenewalsTable::class,
            BusyHoursByWeekdayChart::class,
            BusyHoursGrid::class,
            MembersChart::class,
            MembersTable::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 1;
    }
}
