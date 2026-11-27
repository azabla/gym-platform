<?php

declare(strict_types=1);

namespace App\Filament\Staff\Widgets;

use App\Dashboards\StaffDashboard;
use App\Domains\Attendance\Models\CheckIn;
use App\Filament\Staff\Resources\CheckIns\CheckInResource;
use App\Filament\Staff\Widgets\Concerns\ReadsDashboardFilters;
use Filament\Support\Icons\Heroicon;
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
        $toReview = CheckIn::query()->madeAtAnyOf($this->branchIds())->where('server_disagrees', true)->count();

        $stats = [
            Stat::make(__('Check-ins'), (string) $checkIns['allowed'])
                ->description(__(':count turned away', ['count' => $checkIns['denied']]))
                ->descriptionIcon(Heroicon::OutlinedArrowRightEndOnRectangle)
                ->color($checkIns['denied'] > 0 ? 'warning' : 'success')
                ->chart($this->checkInsOfTheLastWeek()),
            Stat::make(__('Members with a valid membership today'), (string) $members['active'])
                ->description(__('Active or frozen'))
                ->descriptionIcon(Heroicon::OutlinedUsers)
                ->color('primary'),
            Stat::make(__('New members'), (string) $members['new'])
                ->description(__('Registered in the period'))
                ->descriptionIcon(Heroicon::OutlinedUserPlus)
                ->color('info'),
            Stat::make(__('Memberships sold'), (string) ($sold['at_the_desk'] + $sold['in_the_app']))
                ->description(__(':desk at the desk, :app in the app', ['desk' => $sold['at_the_desk'], 'app' => $sold['in_the_app']]))
                ->descriptionIcon(Heroicon::OutlinedShoppingCart)
                ->color('success'),
        ];

        // Offline scans the server would have decided otherwise (ADR 0038).
        if ($toReview > 0) {
            $stats[] = Stat::make(__('Check-ins to review'), (string) $toReview)
                ->description(__('Made offline; the server disagrees'))
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color('warning')
                ->url(CheckInResource::getUrl('index', ['filters' => ['needs_review' => ['isActive' => true]]]));
        }

        return $stats;
    }

    /**
     * Visits let in on each of the seven days up to the period's end, for
     * the check-ins stat's sparkline.
     *
     * @return list<float>
     */
    private function checkInsOfTheLastWeek(): array
    {
        $trends = app(StaffDashboard::class)->trends($this->gym(), $this->to()->subDays(6), $this->to(), $this->branchIds(), seesMoney: false);

        return array_map(fn (array $day): float => (float) $day['allowed'], $trends['check_ins_by_day']);
    }
}
