<?php

declare(strict_types=1);

namespace App\Filament\Staff\Widgets;

use App\Dashboards\StaffDashboard;
use App\Filament\Staff\Widgets\Concerns\ReadsDashboardFilters;
use Filament\Widgets\ChartWidget;

/**
 * At which hours of the day, where the gym is, members came in during the
 * period: the gym's busy hours. See ADR 0024.
 */
class BusyHoursChart extends ChartWidget
{
    use ReadsDashboardFilters;

    protected static ?int $sort = 6;

    protected ?string $heading = 'Busy hours';

    protected ?string $maxHeight = '260px';

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $hours = app(StaffDashboard::class)->trends($this->gym(), $this->from(), $this->to(), $this->branchIds(), false)['check_ins_by_hour'];

        return [
            'datasets' => [[
                'label' => 'Let in',
                'data' => array_column($hours, 'allowed'),
            ]],
            'labels' => array_map(fn (array $hour): string => sprintf('%02d:00', $hour['hour']), $hours),
        ];
    }
}
