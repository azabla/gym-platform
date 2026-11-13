<?php

declare(strict_types=1);

namespace App\Filament\Staff\Widgets;

use App\Dashboards\StaffDashboard;
use App\Filament\Staff\Widgets\Concerns\ReadsDashboardFilters;
use Filament\Widgets\ChartWidget;

/**
 * Members let in and turned away each day of the period, where the gym is.
 * See ADR 0024.
 */
class CheckInsChart extends ChartWidget
{
    use ReadsDashboardFilters;

    protected static ?int $sort = 5;

    protected ?string $heading = 'Check-ins each day';

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
        $days = app(StaffDashboard::class)->trends($this->gym(), $this->from(), $this->to(), $this->branchIds(), false)['check_ins_by_day'];

        return [
            'datasets' => [
                ['label' => 'Let in', 'data' => array_column($days, 'allowed'), 'backgroundColor' => '#16a34a'],
                ['label' => 'Turned away', 'data' => array_column($days, 'denied'), 'backgroundColor' => '#dc2626'],
            ],
            'labels' => array_column($days, 'date'),
        ];
    }
}
