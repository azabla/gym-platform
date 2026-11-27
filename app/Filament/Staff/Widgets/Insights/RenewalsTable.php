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
 * Renewals' figures month by month, and the plan that lapsed most (ADR 0040).
 */
class RenewalsTable extends TableWidget
{
    use ReadsInsightFilters;

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected static function insight(): Insight
    {
        return Insight::Renewals;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Renewals, month by month'))
            ->records(fn (): array => array_reverse(array_map(fn (array $month): array => [
                'month' => self::monthLabel($month['month']),
                'ended' => $month['ended'],
                'renewed' => $month['renewed'],
                'lapsed' => $month['lapsed'],
                'waiting' => $month['waiting'],
                'rate' => $month['rate'],
                'lapsed_most' => self::lapsedMost($month['by_plan']),
            ], $this->insights()->renewals($this->gym(), $this->months(), $this->branchIds()))))
            ->columns([
                TextColumn::make('month')->label(__('Month'))->weight(FontWeight::SemiBold),
                TextColumn::make('ended')->label(__('Ended'))->alignEnd(),
                TextColumn::make('renewed')->label(__('Renewed'))->alignEnd()->color('success'),
                TextColumn::make('lapsed')->label(__('Lapsed'))->alignEnd()->color('danger'),
                TextColumn::make('waiting')->label(__('Waiting'))->alignEnd()->color('gray'),
                TextColumn::make('rate')
                    ->label(__('Renewal rate'))
                    ->alignEnd()
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => is_numeric($state) ? number_format((float) $state * 100, 1).'%' : '—')
                    ->color(fn (mixed $state): string => ! is_numeric($state) ? 'gray' : ((float) $state >= 0.6 ? 'success' : ((float) $state >= 0.4 ? 'warning' : 'danger'))),
                TextColumn::make('lapsed_most')->label(__('Lapsed most'))->placeholder('—')->color('gray'),
            ])
            ->headerActions([DownloadInsightAction::for(self::insight())->chosen(['months' => $this->months()])])
            ->paginated(false);
    }

    /**
     * The plan with the most lapsed memberships, such as "Monthly (4)".
     *
     * @param  array<string, array{renewed: int, lapsed: int, waiting: int}>  $byPlan
     */
    private static function lapsedMost(array $byPlan): ?string
    {
        $lapsed = array_filter(array_map(fn (array $plan): int => $plan['lapsed'], $byPlan));

        if ($lapsed === []) {
            return null;
        }

        arsort($lapsed);

        return array_key_first($lapsed).' ('.reset($lapsed).')';
    }
}
