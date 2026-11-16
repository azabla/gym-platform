<?php

declare(strict_types=1);

namespace App\Filament\Staff\Widgets;

use App\Dashboards\StaffDashboard;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Domains\Shared\Money\Money;
use App\Filament\Staff\Widgets\Concerns\ReadsDashboardFilters;
use Filament\Widgets\ChartWidget;

/**
 * What was taken each day of the period, for the roles that see the gym's
 * money. See ADR 0024.
 */
class TakingsChart extends ChartWidget
{
    use ReadsDashboardFilters;

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Taken each day';

    protected ?string $maxHeight = '260px';

    public static function canView(): bool
    {
        return app(CurrentStaff::class)->get()->role->seesMoney();
    }

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        /** @var list<array{date: string, net: Money}> $days */
        $days = app(StaffDashboard::class)->trends($this->gym(), $this->from(), $this->to(), $this->branchIds(), true)['takings_by_day'] ?? [];

        return [
            'datasets' => [[
                'label' => 'Taken (ETB)',
                'data' => array_map(fn (array $day): float => (float) $day['net']->toDecimal(), $days),
            ]],
            'labels' => array_column($days, 'date'),
        ];
    }
}
