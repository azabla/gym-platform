<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\BranchClosures;

use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\BranchClosure;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Filament\Staff\NavigationGroup;
use App\Filament\Staff\Resources\BranchClosures\Pages\ListBranchClosures;
use App\Filament\Staff\Support\Tables;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * Days the gym's branches are closed or open other hours, such as holidays
 * and repairs, at the branches the staff member works at. Owners and
 * managers add and take them back; every staff member sees them. See
 * ADR 0037 and ADR 0033.
 */
class BranchClosureResource extends Resource
{
    protected static ?string $model = BranchClosure::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::GymSetup;

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Closures';

    protected static ?string $modelLabel = 'closure';

    public static function table(Table $table): Table
    {
        return Tables::standard($table, Heroicon::OutlinedCalendarDays, 'No closures', 'Add holidays and repair days, so check-ins are turned away and members are told.')
            ->defaultSort('starts_on')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['branch', 'createdBy.user']))
            ->columns([
                TextColumn::make('branch.name')->label('Branch')->badge()->color('gray')->sortable(),
                TextColumn::make('starts_on')
                    ->label('Days')
                    ->state(fn (BranchClosure $record): string => self::daysOf($record))
                    ->description(fn (BranchClosure $record): string => trans_choice('{1} 1 day|[2,*] :count days', self::lengthOf($record)))
                    ->weight(FontWeight::SemiBold)
                    ->sortable(),
                TextColumn::make('hours')
                    ->label('Hours')
                    ->state(fn (BranchClosure $record): string => self::hoursOf($record))
                    ->badge()
                    ->color(fn (BranchClosure $record): string => $record->isClosedAllDay() ? 'danger' : 'warning')
                    ->icon(fn (BranchClosure $record): Heroicon => $record->isClosedAllDay() ? Heroicon::OutlinedLockClosed : Heroicon::OutlinedClock),
                TextColumn::make('reason')
                    ->searchable()
                    ->wrap()
                    ->description(fn (BranchClosure $record): ?string => $record->repeats_yearly ? 'Yearly, on '.$record->ethiopianStart() : null),
                TextColumn::make('createdBy.user.name')->label('Added by')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->searchPlaceholder('Reason')
            ->filters([
                SelectFilter::make('when')
                    ->label('When')
                    ->options(['coming' => 'Today and coming', 'past' => 'Ended'])
                    ->default('coming')
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'coming' => $query->whereDate('ends_on', '>=', self::today()->toDateString()),
                        'past' => $query->whereDate('ends_on', '<', self::today()->toDateString()),
                        default => $query,
                    }),
                SelectFilter::make('branch')
                    ->label('Branch')
                    ->relationship('branch', 'name', fn (Builder $query): Builder => $query->whereKey(self::branchIds())),
                Tables::dateRange('starts', 'starts_on', 'Starts'),
            ])
            ->recordActions([
                Action::make('remove')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->visible(fn (BranchClosure $record): bool => Gate::allows('update', $record->branch))
                    ->requiresConfirmation()
                    ->modalHeading('Take this closure back?')
                    ->modalDescription(fn (BranchClosure $record): string => "{$record->branch->name} keeps its weekly hours on ".self::daysOf($record).'.'
                        .($record->members_told_at === null ? '' : ' Members who were told of the closure hear it is open as usual.'))
                    ->action(function (BranchClosure $record): void {
                        $record->takeBack();

                        Notification::make()->success()->title('Closure taken back.')->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBranchClosures::route('/'),
        ];
    }

    /**
     * Only closures at the branches the staff member works at.
     *
     * @return Builder<BranchClosure>
     */
    public static function getEloquentQuery(): Builder
    {
        return BranchClosure::query()->whereIn('branch_id', self::branchIds());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * "Mon 26 Oct" or "Mon 26 Oct – Tue 27 Oct".
     */
    public static function daysOf(BranchClosure $closure): string
    {
        $from = $closure->starts_on->format('D j M');

        return $closure->starts_on->equalTo($closure->ends_on) ? $from : $from.' – '.$closure->ends_on->format('D j M');
    }

    /**
     * "Closed" or "08:00–12:00", with "(next day)" for hours past midnight.
     */
    public static function hoursOf(BranchClosure $closure): string
    {
        $window = $closure->window();

        if ($window === null) {
            return 'Closed';
        }

        return substr($window->opensAt, 0, 5).'–'.substr($window->closesAt, 0, 5).($window->runsPastMidnight() ? ' (next day)' : '');
    }

    private static function lengthOf(BranchClosure $closure): int
    {
        return (int) $closure->starts_on->diffInDays($closure->ends_on) + 1;
    }

    /**
     * @return list<int>
     */
    public static function branchIds(): array
    {
        return array_map('intval', app(CurrentStaff::class)->get()->branchesWorkedAt()->modelKeys());
    }

    /**
     * The branches the staff member may close: owners and managers, where
     * they work.
     *
     * @return array<int, string> names by id
     */
    public static function branchesTheyMayClose(): array
    {
        return app(CurrentStaff::class)->get()->branchesWorkedAt()
            ->filter(fn (Branch $branch): bool => Gate::allows('update', $branch))
            ->pluck('name', 'id')
            ->map(fn (mixed $name): string => (string) $name)
            ->all();
    }

    private static function today(): CarbonImmutable
    {
        return app(CurrentStaff::class)->get()->organization->today();
    }
}
