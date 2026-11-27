<?php

declare(strict_types=1);

namespace App\Filament\Staff\Widgets\Insights;

use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Shared\Money\Money;
use App\Filament\Staff\Actions\DownloadInsightAction;
use App\Insights\Insight;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Revenue's figures month by month: the net and each method (ADR 0040).
 */
class RevenueTable extends TableWidget
{
    use ReadsInsightFilters;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static function insight(): Insight
    {
        return Insight::Revenue;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Revenue, month by month'))
            ->records(fn (): array => array_reverse(array_map(fn (array $month): array => [
                'month' => self::monthLabel($month['month']),
                'net' => $month['net'],
                ...array_combine(
                    array_map(fn (PaymentMethod $method): string => $method->value, PaymentMethod::cases()),
                    array_values($month['by_method']),
                ),
            ], $this->insights()->revenue($this->gym(), $this->months(), $this->branchIds()))))
            ->columns([
                TextColumn::make('month')->label(__('Month'))->weight(FontWeight::SemiBold),
                self::money('net', __('Net'))->weight(FontWeight::Bold),
                ...array_map(fn (PaymentMethod $method): TextColumn => self::money($method->value, __($method->label()))->size(TextSize::Small)->color('gray'), PaymentMethod::cases()),
            ])
            ->headerActions([DownloadInsightAction::for(self::insight())->chosen(['months' => $this->months()])])
            ->paginated(false);
    }

    private static function money(string $name, string $label): TextColumn
    {
        return TextColumn::make($name)
            ->label($label)
            ->alignEnd()
            ->fontFamily('mono')
            ->formatStateUsing(fn (mixed $state): string => $state instanceof Money ? $state->format() : '')
            ->color(fn (mixed $state): ?string => $state instanceof Money && $state->isNegative() ? 'danger' : null);
    }
}
