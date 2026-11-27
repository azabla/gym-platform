<?php

declare(strict_types=1);

namespace App\Filament\Staff\Widgets\Insights;

use App\Insights\Insight;
use Filament\Widgets\ChartWidget;

/**
 * Money taken each month, net of refunds, stacked by plan (ADR 0040).
 */
class RevenueChart extends ChartWidget
{
    use ReadsInsightFilters;

    private const COLOURS = ['#f97316', '#0ea5e9', '#10b981', '#a855f7', '#f43f5e', '#eab308', '#64748b'];

    protected static ?int $sort = 1;

    protected ?string $heading = 'Revenue by month';

    protected ?string $description = 'Net of refunds, by plan.';

    protected ?string $maxHeight = '300px';

    protected int|string|array $columnSpan = 'full';

    protected static function insight(): Insight
    {
        return Insight::Revenue;
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $months = $this->insights()->revenue($this->gym(), $this->months(), $this->branchIds());
        $plans = array_values(array_unique(array_merge(...array_map(fn (array $month): array => array_keys($month['by_plan']), $months))));
        sort($plans);

        return [
            'datasets' => array_map(fn (string $plan, int $index): array => [
                'label' => $plan,
                'data' => array_map(fn (array $month): float => isset($month['by_plan'][$plan]) ? (float) $month['by_plan'][$plan]->toDecimal() : 0.0, $months),
                'backgroundColor' => self::COLOURS[$index % count(self::COLOURS)],
            ], $plans, array_keys($plans)),
            'labels' => array_map(fn (array $month): string => self::monthLabel($month['month']), $months),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return ['scales' => ['x' => ['stacked' => true], 'y' => ['stacked' => true]]];
    }
}
