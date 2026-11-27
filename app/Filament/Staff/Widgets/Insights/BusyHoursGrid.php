<?php

declare(strict_types=1);

namespace App\Filament\Staff\Widgets\Insights;

use App\Filament\Staff\Actions\DownloadInsightAction;
use App\Insights\Insight;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Busiest hours as a weekday-by-hour grid, each cell shaded by how busy it
 * is, showing only the hours anyone came in (ADR 0040).
 */
class BusyHoursGrid extends TableWidget
{
    use ReadsInsightFilters;

    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    protected static function insight(): Insight
    {
        return Insight::BusyHours;
    }

    public function table(Table $table): Table
    {
        $grid = $this->insights()->busyHours($this->gym(), $this->months(), $this->branchIds())['grid'];
        $busiest = max(1, ...array_map('max', $grid));
        $hours = array_values(array_filter(range(0, 23), fn (int $hour): bool => array_sum(array_column($grid, $hour)) > 0)) ?: range(6, 21);

        return $table
            ->heading('Busiest hours, weekday by hour')
            ->records(fn (): array => array_map(fn (int $weekday): array => [
                'weekday' => BusyHoursByWeekdayChart::WEEKDAYS[$weekday],
                ...array_combine(array_map(fn (int $hour): string => "h{$hour}", range(0, 23)), $grid[$weekday]),
            ], array_keys(BusyHoursByWeekdayChart::WEEKDAYS)))
            ->columns([
                TextColumn::make('weekday')->label('')->weight(FontWeight::SemiBold),
                ...array_map(fn (int $hour): TextColumn => TextColumn::make("h{$hour}")
                    ->label(sprintf('%02d', $hour))
                    ->alignCenter()
                    ->extraCellAttributes(fn (array $record): array => [
                        'style' => sprintf('background-color: rgba(249, 115, 22, %.2f)', ((int) $record["h{$hour}"]) / $busiest * 0.85),
                    ]), $hours),
            ])
            ->headerActions([DownloadInsightAction::for(self::insight())->chosen(['months' => $this->months()])])
            ->paginated(false);
    }
}
