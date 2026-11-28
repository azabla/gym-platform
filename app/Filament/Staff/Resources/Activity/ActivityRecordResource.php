<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\Activity;

use App\Domains\Activity\Enums\ActivityAction;
use App\Domains\Activity\Models\ActivityRecord;
use App\Domains\Organizations\Models\Staff;
use App\Filament\Staff\NavigationGroup;
use App\Filament\Staff\Resources\Activity\Pages\ListActivityRecords;
use App\Filament\Staff\Resources\Concerns\LimitsToTheStaffMembersBranches;
use App\Filament\Staff\Resources\Concerns\ShowsGymTime;
use App\Filament\Staff\Support\ActivityText;
use App\Filament\Staff\Support\Dates;
use App\Filament\Staff\Support\Tables;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Who did what with the gym's money, newest first, for owners and managers
 * (ADR 0046): payments, refunds and voids, sales and cancellations, discounts,
 * payment links, plan prices and promo codes. Each line opens what it was
 * about; flagged ones are what an owner checks first.
 */
class ActivityRecordResource extends Resource
{
    use LimitsToTheStaffMembersBranches;
    use ShowsGymTime;

    protected static ?string $model = ActivityRecord::class;

    protected static ?string $slug = 'activity';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Money;

    protected static ?int $navigationSort = 9;

    public static function getModelLabel(): string
    {
        return __('activity');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Activity');
    }

    public static function getNavigationLabel(): string
    {
        return __('Activity');
    }

    public static function table(Table $table): Table
    {
        return Tables::standard($table, Heroicon::OutlinedClipboardDocumentList, __('Nothing here'), __('Payments, refunds, sales and price changes show here as staff make them. Try other filters.'))
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('occurred_at')->orderByDesc('id'))
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['staff.user', 'branch']))
            ->recordUrl(fn (ActivityRecord $record): ?string => ActivityText::urlOf($record))
            ->columns([
                TextColumn::make('occurred_at')->label(__('When'))->formatStateUsing(Dates::asDateTime(self::gymTimezone()))->sortable(),
                TextColumn::make('staff.user.name')->label(__('Who'))->placeholder(__('Online, or the platform'))->weight('semibold'),
                TextColumn::make('action')
                    ->label(__('What'))
                    ->formatStateUsing(fn (ActivityAction $state): string => __($state->label()))
                    ->badge()
                    ->color(fn (ActivityRecord $record): string => $record->flagged ? 'danger' : 'gray'),
                TextColumn::make('details')
                    ->label(__('Details'))
                    ->state(fn (ActivityRecord $record): string => ActivityText::of($record))
                    ->wrap(),
                TextColumn::make('branch.name')->label(__('Branch'))->badge()->color('gray')->placeholder(__('The whole gym'))->toggleable(),
                IconColumn::make('flagged')
                    ->label(__('Flagged'))
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedFlag)
                    ->trueColor('danger')
                    ->falseIcon(Heroicon::OutlinedMinus)
                    ->falseColor('gray')
                    ->toggleable(),
            ])
            ->filters([
                Tables::dateRange('occurred_at', 'occurred_at', __('Done'), isMoment: true),
                SelectFilter::make('action')->label(__('What'))->native(false)->multiple()
                    ->options(collect(ActivityAction::cases())->mapWithKeys(fn (ActivityAction $action): array => [$action->value => __($action->label())])->all()),
                SelectFilter::make('staff')->label(__('Who'))->native(false)->searchable()
                    ->options(fn (): array => Staff::query()->withTrashed()->with('user')->get()
                        ->mapWithKeys(fn (Staff $staff): array => [$staff->id => (string) $staff->user?->name])
                        ->sort()
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $query->where('staff_id', (int) $data['value']) : $query),
                SelectFilter::make('branch')->label(__('Branch'))->native(false)->relationship('branch', 'name', self::onlyTheirBranches(...)),
                TernaryFilter::make('flagged')->label(__('Flagged'))->native(false)
                    ->placeholder(__('Flagged or not'))
                    ->trueLabel(__('Only flagged'))
                    ->falseLabel(__('Not flagged')),
            ]);
    }

    /**
     * Owners see the whole gym; a manager limited to some branches sees those
     * branches' records and the gym-wide ones (ADR 0025).
     *
     * @return Builder<ActivityRecord>
     */
    public static function getEloquentQuery(): Builder
    {
        return ActivityRecord::query()->seenAtBranches(self::staffBranchIds());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivityRecords::route('/'),
        ];
    }
}
