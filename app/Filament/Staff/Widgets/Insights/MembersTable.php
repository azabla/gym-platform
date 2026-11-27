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
 * Members and visit packs month by month (ADR 0040).
 */
class MembersTable extends TableWidget
{
    use ReadsInsightFilters;

    protected static ?int $sort = 8;

    protected int|string|array $columnSpan = 'full';

    protected static function insight(): Insight
    {
        return Insight::Members;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Members and visit packs, month by month'))
            ->records(fn (): array => array_reverse(array_map(fn (array $month): array => [
                ...$month,
                'month' => self::monthLabel($month['month']),
                'pack_use' => $month['visits_bought'] === 0 ? null : $month['visits_used'] / $month['visits_bought'],
            ], $this->insights()->members($this->gym(), $this->months(), $this->branchIds()))))
            ->columns([
                TextColumn::make('month')->label(__('Month'))->weight(FontWeight::SemiBold),
                TextColumn::make('active')->label(__('Active at month end'))->alignEnd()->weight(FontWeight::Bold),
                TextColumn::make('new')->label(__('New members'))->alignEnd()->color('info'),
                TextColumn::make('packs_ended')->label(__('Visit packs ended'))->alignEnd(),
                TextColumn::make('visits_used')
                    ->label(__('Visits used'))
                    ->alignEnd()
                    ->formatStateUsing(fn (mixed $state, array $record): string => "{$state} of {$record['visits_bought']}"),
                TextColumn::make('pack_use')
                    ->label(__('Pack use'))
                    ->alignEnd()
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => is_numeric($state) ? number_format((float) $state * 100).'%' : '—')
                    ->color(fn (mixed $state): string => is_numeric($state) ? ((float) $state >= 0.7 ? 'success' : 'warning') : 'gray'),
                TextColumn::make('packs_with_visits_left')->label(__('Ended with visits left'))->alignEnd()->color('gray'),
            ])
            ->headerActions([DownloadInsightAction::for(self::insight())->chosen(['months' => $this->months()])])
            ->paginated(false);
    }
}
