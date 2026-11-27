<?php

declare(strict_types=1);

namespace App\Filament\Staff\Widgets\Insights;

use App\Filament\Staff\Support\TranslatesItsHeading;
use App\Insights\Insight;
use Filament\Widgets\ChartWidget;

/**
 * Members let in at each hour, one line a weekday, where the gym is
 * (ADR 0040).
 */
class BusyHoursByWeekdayChart extends ChartWidget
{
    use ReadsInsightFilters;
    use TranslatesItsHeading;

    public const WEEKDAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    private const COLOURS = [1 => '#f97316', 2 => '#0ea5e9', 3 => '#10b981', 4 => '#a855f7', 5 => '#eab308', 6 => '#f43f5e', 7 => '#64748b'];

    protected static ?int $sort = 5;

    protected ?string $heading = 'Busiest hours';

    protected ?string $description = 'Members let in at each hour, where the gym is.';

    protected ?string $maxHeight = '300px';

    protected int|string|array $columnSpan = 'full';

    protected static function insight(): Insight
    {
        return Insight::BusyHours;
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
        $grid = $this->insights()->busyHours($this->gym(), $this->months(), $this->branchIds())['grid'];

        return [
            'datasets' => array_map(fn (int $weekday): array => [
                'label' => __(self::WEEKDAYS[$weekday]),
                'data' => $grid[$weekday],
                'borderColor' => self::COLOURS[$weekday],
                'backgroundColor' => self::COLOURS[$weekday],
                'tension' => 0.3,
            ], array_keys(self::WEEKDAYS)),
            'labels' => array_map(fn (int $hour): string => sprintf('%02d:00', $hour), range(0, 23)),
        ];
    }
}
