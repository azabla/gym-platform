<?php

declare(strict_types=1);

namespace App\Filament\Staff\Widgets\Insights;

use App\Filament\Staff\Support\TranslatesItsHeading;
use App\Insights\Insight;
use Filament\Widgets\ChartWidget;

/**
 * Members with a valid membership at each month's end and new members, as
 * lines, and visit-pack visits bought and used, as bars (ADR 0040).
 */
class MembersChart extends ChartWidget
{
    use ReadsInsightFilters;
    use TranslatesItsHeading;

    protected static ?int $sort = 7;

    protected ?string $heading = 'Members and visit packs';

    protected ?string $description = 'Active members at each month\'s end, new members, and how fully visit packs that ended were used.';

    protected ?string $maxHeight = '300px';

    protected int|string|array $columnSpan = 'full';

    protected static function insight(): Insight
    {
        return Insight::Members;
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
        $months = $this->insights()->members($this->gym(), $this->months(), $this->branchIds());

        return [
            'datasets' => [
                ['type' => 'line', 'label' => __('Active members'), 'data' => array_column($months, 'active'), 'borderColor' => '#f97316', 'backgroundColor' => '#f97316'],
                ['type' => 'line', 'label' => __('New members'), 'data' => array_column($months, 'new'), 'borderColor' => '#0ea5e9', 'backgroundColor' => '#0ea5e9'],
                ['label' => __('Pack visits bought'), 'data' => array_column($months, 'visits_bought'), 'backgroundColor' => '#cbd5e1'],
                ['label' => __('Pack visits used'), 'data' => array_column($months, 'visits_used'), 'backgroundColor' => '#10b981'],
            ],
            'labels' => array_map(fn (array $month): string => self::monthLabel($month['month']), $months),
        ];
    }
}
