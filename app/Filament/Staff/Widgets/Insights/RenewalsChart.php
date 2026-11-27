<?php

declare(strict_types=1);

namespace App\Filament\Staff\Widgets\Insights;

use App\Insights\Insight;
use Filament\Widgets\ChartWidget;

/**
 * Memberships that ended each month, renewed or lapsed, with the renewal
 * rate as a line (ADR 0040).
 */
class RenewalsChart extends ChartWidget
{
    use ReadsInsightFilters;

    protected static ?int $sort = 3;

    protected ?string $heading = 'Renewals and churn';

    protected ?string $description = 'Renewed means the next membership started within 14 days of the end.';

    protected ?string $maxHeight = '300px';

    protected int|string|array $columnSpan = 'full';

    protected static function insight(): Insight
    {
        return Insight::Renewals;
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
        $months = $this->insights()->renewals($this->gym(), $this->months(), $this->branchIds());

        return [
            'datasets' => [
                ['label' => 'Renewed', 'data' => array_column($months, 'renewed'), 'backgroundColor' => '#10b981', 'stack' => 'ended'],
                ['label' => 'Lapsed', 'data' => array_column($months, 'lapsed'), 'backgroundColor' => '#f43f5e', 'stack' => 'ended'],
                ['label' => 'Waiting', 'data' => array_column($months, 'waiting'), 'backgroundColor' => '#94a3b8', 'stack' => 'ended'],
                [
                    'type' => 'line',
                    'label' => 'Renewal rate (%)',
                    'data' => array_map(fn (array $month): ?float => $month['rate'] === null ? null : round($month['rate'] * 100, 1), $months),
                    'borderColor' => '#f97316',
                    'backgroundColor' => '#f97316',
                    'yAxisID' => 'rate',
                ],
            ],
            'labels' => array_map(fn (array $month): string => self::monthLabel($month['month']), $months),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return ['scales' => [
            'x' => ['stacked' => true],
            'y' => ['stacked' => true, 'beginAtZero' => true],
            'rate' => ['position' => 'right', 'min' => 0, 'max' => 100, 'grid' => ['drawOnChartArea' => false]],
        ]];
    }
}
