<?php

declare(strict_types=1);

namespace App\Filament\Staff\Support;

use App\Domains\Organizations\Tenancy\CurrentOrganization;
use BackedEnum;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The staff panel's table conventions in one place (ADR 0033): striped rows,
 * 25 to a page, filters above the table, money aligned right, badges from
 * Badges, date ranges in the gym's days, and an empty state that says what
 * to do.
 */
final class Tables
{
    /**
     * The look every list shares, with an empty state.
     */
    public static function standard(Table $table, Heroicon $emptyIcon, string $emptyHeading, string $emptyDescription): Table
    {
        return $table
            ->striped()
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->filtersLayout(FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(['default' => 1, 'sm' => 2, 'lg' => 4])
            ->emptyStateIcon($emptyIcon)
            ->emptyStateHeading($emptyHeading)
            ->emptyStateDescription($emptyDescription);
    }

    /**
     * The records on the table's current page, to look up what they show in
     * one query for the page rather than one per row.
     *
     * @return list<Model>
     */
    public static function pageRecords(HasTable $livewire): array
    {
        $records = $livewire->getTableRecords();

        return array_values($records instanceof Paginator || $records instanceof CursorPaginator ? $records->items() : $records->all());
    }

    /**
     * An amount of money, aligned right, from the state given (a Money, or
     * null for none). The closure takes what Filament injects into a column's
     * state, such as the record and the table.
     */
    public static function money(string $name, Closure $money, ?string $label = null): TextColumn
    {
        return TextColumn::make($name)
            ->label($label ?? __(ucfirst(str_replace('_', ' ', $name))))
            ->state($money)
            ->formatStateUsing(fn (mixed $state): string => is_object($state) && method_exists($state, 'format') ? $state->format() : (string) $state)
            ->alignEnd()
            ->weight(FontWeight::Medium)
            ->extraAttributes(['class' => 'tabular-nums']);
    }

    /**
     * A badge for an enum column, labelled, coloured and with an icon from Badges.
     */
    public static function badge(string $name, ?string $label = null): TextColumn
    {
        return TextColumn::make($name)
            ->label($label ?? __(ucfirst(str_replace('_', ' ', $name))))
            ->badge()
            ->formatStateUsing(fn (mixed $state): string => $state instanceof BackedEnum ? Badges::label($state) : (string) $state)
            ->color(fn (mixed $state): string => $state instanceof BackedEnum ? Badges::color($state) : 'gray')
            ->icon(fn (mixed $state): ?Heroicon => $state instanceof BackedEnum ? Badges::icon($state) : null);
    }

    /**
     * A from-and-to filter on a column, both days included. A moment column
     * (created_at, checked_in_at) is compared in the gym's days, so "today"
     * means the gym's today.
     */
    public static function dateRange(string $name, string $column, string $label, bool $isMoment = false): Filter
    {
        return Filter::make($name)
            ->label($label)
            ->schema([
                DatePicker::make('from')->hint(Dates::ethiopianHint())->live()->label(__(':label from', ['label' => $label]))->native(false)->displayFormat('j M Y'),
                DatePicker::make('to')->hint(Dates::ethiopianHint())->live()->label(__(':label to', ['label' => $label]))->native(false)->displayFormat('j M Y')->afterOrEqual('from'),
            ])
            ->columns(2)
            ->columnSpan(2)
            ->query(function (Builder $query, array $data) use ($column, $isMoment): Builder {
                $from = filled($data['from'] ?? null) ? (string) $data['from'] : null;
                $to = filled($data['to'] ?? null) ? (string) $data['to'] : null;

                if (! $isMoment) {
                    return $query
                        ->when($from, fn (Builder $query) => $query->where($column, '>=', $from))
                        ->when($to, fn (Builder $query) => $query->where($column, '<=', $to));
                }

                $timezone = app(CurrentOrganization::class)->get()->timezone;

                return $query
                    ->when($from, fn (Builder $query) => $query->where($column, '>=', CarbonImmutable::parse($from, $timezone)->startOfDay()->utc()))
                    ->when($to, fn (Builder $query) => $query->where($column, '<', CarbonImmutable::parse($to, $timezone)->addDay()->startOfDay()->utc()));
            })
            ->indicateUsing(function (array $data) use ($label): array {
                $indicators = [];

                if (filled($data['from'] ?? null)) {
                    $indicators[] = Indicator::make(__(':label from :date', ['label' => $label, 'date' => Dates::short((string) $data['from'])]))->removeField('from');
                }

                if (filled($data['to'] ?? null)) {
                    $indicators[] = Indicator::make(__(':label to :date', ['label' => $label, 'date' => Dates::short((string) $data['to'])]))->removeField('to');
                }

                return $indicators;
            });
    }
}
