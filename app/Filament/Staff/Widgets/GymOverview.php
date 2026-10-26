<?php

declare(strict_types=1);

namespace App\Filament\Staff\Widgets;

use App\Dashboards\StaffDashboard;
use App\Filament\Staff\Widgets\Concerns\ReadsDashboardFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Check-ins, members and memberships sold, which every staff member sees.
 */
class GymOverview extends StatsOverviewWidget
{
    use ReadsDashboardFilters;

    protected static ?int $sort = 2;

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $dashboard = app(StaffDashboard::class);
        $checkIns = $dashboard->checkIns($this->gym(), $this->from(), $this->to(), $this->branchIds());
        $members = $dashboard->members($this->gym(), $this->from(), $this->to(), $this->branchIds());
        $sold = $members['memberships_sold'];

        return [
            Stat::make('Check-ins', (string) $checkIns['allowed'])
                ->description("{$checkIns['denied']} turned away"),
            Stat::make('Members with a valid membership today', (string) $members['active']),
            Stat::make('New members', (string) $members['new']),
            Stat::make('Memberships sold', (string) ($sold['at_the_desk'] + $sold['in_the_app']))
                ->description("{$sold['at_the_desk']} at the desk, {$sold['in_the_app']} in the app"),
        ];
    }
}
