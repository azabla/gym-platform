<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\CashCounts;

use App\Domains\Billing\Exceptions\CashCountRefused;
use App\Domains\Billing\Models\CashCount;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Domains\Shared\Money\Exceptions\InvalidMoneyAmount;
use App\Domains\Shared\Money\Money;
use App\Filament\Staff\NavigationGroup;
use App\Filament\Staff\Resources\CashCounts\Pages\ListCashCounts;
use App\Filament\Staff\Resources\Concerns\LimitsToTheStaffMembersBranches;
use App\Filament\Staff\Resources\Concerns\ShowsGymTime;
use App\Filament\Staff\Support\Dates;
use App\Filament\Staff\Support\Tables;
use App\Rules\ValidMoneyAmount;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * Cash counts (ADR 0046): each staff member closes their own count of the
 * cash they took at a branch, with a note when it is off; owners and
 * managers see everyone's at their branches and mark them checked;
 * accountants see them all; receptionists only their own.
 */
class CashCountResource extends Resource
{
    use LimitsToTheStaffMembersBranches;
    use ShowsGymTime;

    protected static ?string $model = CashCount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Money;

    protected static ?int $navigationSort = 3;

    public static function getModelLabel(): string
    {
        return __('cash count');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Cash counts');
    }

    public static function getNavigationLabel(): string
    {
        return __('Cash counts');
    }

    public static function table(Table $table): Table
    {
        return Tables::standard($table, Heroicon::OutlinedCalculator, __('No cash counts yet'), __('Close your cash at the end of a shift with Close my cash.'))
            ->defaultSort('closed_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['staff.user', 'branch', 'checkedBy.user']))
            ->columns([
                TextColumn::make('closed_at')->label(__('Closed'))->formatStateUsing(Dates::asDateTime(self::gymTimezone()))->sortable(),
                TextColumn::make('staff.user.name')->label(__('Counted by'))->weight('semibold'),
                TextColumn::make('branch.name')->label(__('Branch'))->badge()->color('gray'),
                Tables::money('expected', fn (CashCount $record): Money => $record->expected, __('Should have')),
                Tables::money('counted', fn (CashCount $record): Money => $record->counted, __('Counted')),
                Tables::money('difference', fn (CashCount $record): Money => $record->difference(), __('Difference'))
                    ->color(fn (CashCount $record): string => match (true) {
                        $record->difference()->isNegative() => 'danger',
                        $record->difference()->isPositive() => 'warning',
                        default => 'success',
                    }),
                TextColumn::make('note')->label(__('Note'))->placeholder(__('None'))->wrap()->toggleable(),
                TextColumn::make('checked_at')
                    ->label(__('Checked'))
                    ->state(fn (CashCount $record): string => $record->checked_at === null ? __('Not checked') : __('Checked by :name', ['name' => (string) $record->checkedBy?->user->name]))
                    ->badge()
                    ->color(fn (CashCount $record): string => $record->checked_at === null ? 'warning' : 'success')
                    ->description(fn (CashCount $record): ?string => $record->check_note),
            ])
            ->filters([
                Tables::dateRange('closed_at', 'closed_at', __('Closed'), isMoment: true),
                SelectFilter::make('staff')->label(__('Counted by'))->native(false)->searchable()
                    ->options(fn (): array => Staff::query()->withTrashed()->with('user')->get()
                        ->mapWithKeys(fn (Staff $staff): array => [$staff->id => (string) $staff->user?->name])
                        ->sort()
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $query->where('staff_id', (int) $data['value']) : $query)
                    ->visible(fn (): bool => self::seesEveryones()),
                SelectFilter::make('branch')->label(__('Branch'))->native(false)->relationship('branch', 'name', self::onlyTheirBranches(...)),
                SelectFilter::make('difference')->label(__('Difference'))->native(false)
                    ->options(['short' => __('Short'), 'over' => __('Over'), 'exact' => __('Exact')])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'short' => $query->whereColumn('counted_minor', '<', 'expected_minor'),
                        'over' => $query->whereColumn('counted_minor', '>', 'expected_minor'),
                        'exact' => $query->whereColumn('counted_minor', 'expected_minor'),
                        default => $query,
                    }),
                TernaryFilter::make('checked')->label(__('Checked'))->native(false)
                    ->attribute('checked_at')
                    ->nullable()
                    ->placeholder(__('Checked or not'))
                    ->trueLabel(__('Checked'))
                    ->falseLabel(__('Not checked')),
            ])
            ->recordActions([
                self::checkAction(),
            ]);
    }

    /**
     * Everyone sees their own counts; owners, managers and accountants see
     * everyone's at their branches (ADR 0025).
     *
     * @return Builder<CashCount>
     */
    public static function getEloquentQuery(): Builder
    {
        $query = CashCount::query();

        if (! self::seesEveryones()) {
            return $query->where('staff_id', app(CurrentStaff::class)->get()->getKey());
        }

        $branchIds = self::staffBranchIds();

        return $branchIds === null ? $query : $query->whereIn('branch_id', $branchIds);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCashCounts::route('/'),
        ];
    }

    /**
     * Close the staff member's own count at a branch: what they should have,
     * what they counted, and the difference as they type.
     */
    public static function closeAction(): Action
    {
        return Action::make('close')
            ->label(__('Close my cash'))
            ->icon(Heroicon::OutlinedCalculator)
            ->visible(fn (): bool => Gate::allows('create', CashCount::class))
            ->modalIcon(Heroicon::OutlinedCalculator)
            ->modalHeading(__('Close my cash'))
            ->modalDescription(__('Count the cash you took at the branch since your last count. Cash you take after this goes to your next count.'))
            ->modalSubmitActionLabel(__('Close my cash'))
            ->fillForm(fn (): array => ['branch_id' => array_key_first(self::branchesWithCashFor(self::me()))])
            ->schema([
                Select::make('branch_id')
                    ->label(__('Branch'))
                    ->options(fn (): array => self::branchesWithCashFor(self::me()))
                    ->native(false)
                    ->required()
                    ->live(),
                TextEntry::make('expected')
                    ->label(__('You should have'))
                    ->state(fn (Get $get): string => self::expectedAt($get)->format())
                    ->weight('bold'),
                TextInput::make('counted')
                    ->label(__('You counted'))
                    ->prefix('ETB')
                    ->placeholder('3,500')
                    ->required()
                    ->live(onBlur: true)
                    ->rules([new ValidMoneyAmount]),
                TextEntry::make('difference')
                    ->label(__('Difference'))
                    ->state(fn (Get $get): string => self::differenceLine($get))
                    ->color(fn (Get $get): string => self::differenceLine($get) === __('Exact') ? 'success' : 'danger'),
                Textarea::make('note')
                    ->label(__('Note'))
                    ->placeholder(__('Gave 50 change twice.'))
                    ->helperText(__('Needed when the count is off.'))
                    ->maxLength(1000),
            ])
            ->action(function (array $data, Action $action): void {
                try {
                    CashCount::close(self::me(), Branch::query()->findOrFail((int) $data['branch_id']), Money::of((string) $data['counted']), isset($data['note']) ? (string) $data['note'] : null);
                } catch (CashCountRefused $refused) {
                    Notification::make()->danger()->title($refused->getMessage())->send();
                    $action->halt();
                }

                Notification::make()->success()->title(__('Cash closed.'))->send();
            });
    }

    public static function checkAction(): Action
    {
        return Action::make('check')
            ->label(__('Mark checked'))
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->visible(fn (CashCount $record): bool => $record->checked_at === null && Gate::allows('check', $record))
            ->modalIcon(Heroicon::OutlinedCheckBadge)
            ->modalHeading(__('Mark this count checked?'))
            ->modalDescription(fn (CashCount $record): string => __(':name counted :counted; they should have had :expected.', [
                'name' => (string) $record->staff->user->name,
                'counted' => $record->counted->format(),
                'expected' => $record->expected->format(),
            ]))
            ->schema([
                Textarea::make('check_note')->label(__('Note'))->placeholder(__('Banked on Monday.'))->helperText(__('Optional.'))->maxLength(1000),
            ])
            ->action(function (CashCount $record, array $data, Action $action): void {
                try {
                    $record->check(self::me(), isset($data['check_note']) ? (string) $data['check_note'] : null);
                } catch (CashCountRefused $refused) {
                    Notification::make()->danger()->title($refused->getMessage())->send();
                    $action->halt();
                }

                Notification::make()->success()->title(__('Count checked.'))->send();
            });
    }

    /**
     * The branches where the staff member has cash to count, first, then the
     * other branches they work at.
     *
     * @return array<int, string>
     */
    private static function branchesWithCashFor(Staff $staff): array
    {
        $withCash = [];
        $others = [];

        foreach ($staff->branchesWorkedAt() as $branch) {
            $expected = CashCount::expectedFor($staff, $branch);

            if ($expected->isZero()) {
                $others[(int) $branch->getKey()] = (string) $branch->name;
            } else {
                $withCash[(int) $branch->getKey()] = __(':branch (:amount to count)', ['branch' => $branch->name, 'amount' => $expected->format()]);
            }
        }

        return $withCash + $others;
    }

    private static function expectedAt(Get $get): Money
    {
        $branch = is_numeric($get('branch_id')) ? Branch::query()->find((int) $get('branch_id')) : null;

        return $branch === null ? Money::zero() : CashCount::expectedFor(self::me(), $branch);
    }

    private static function differenceLine(Get $get): string
    {
        try {
            $difference = Money::of((string) $get('counted'))->subtract(self::expectedAt($get));
        } catch (InvalidMoneyAmount) {
            return __('Enter what you counted.');
        }

        return match (true) {
            $difference->isZero() => __('Exact'),
            $difference->isNegative() => __(':amount short', ['amount' => $difference->negate()->format()]),
            default => __(':amount over', ['amount' => $difference->format()]),
        };
    }

    private static function seesEveryones(): bool
    {
        return Gate::allows('seeEveryones', CashCount::class);
    }

    private static function me(): Staff
    {
        return app(CurrentStaff::class)->get();
    }
}
