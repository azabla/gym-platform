<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\Plans;

use App\Domains\Memberships\Enums\DurationUnit;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Domains\Shared\Money\Exceptions\InvalidMoneyAmount;
use App\Domains\Shared\Money\Money;
use App\Filament\Staff\NavigationGroup;
use App\Filament\Staff\Resources\Plans\Pages\CreatePlan;
use App\Filament\Staff\Resources\Plans\Pages\EditPlan;
use App\Filament\Staff\Resources\Plans\Pages\ListPlans;
use App\Filament\Staff\Support\Tables;
use App\Filament\Staff\Support\TranslatesItsLabels;
use App\Rules\ValidMoneyAmount;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * The plans the gym sells, with the API's rules: a name no other plan has,
 * a length of up to 366 days or 36 months, and branches the staff member
 * works at. A manager limited to some branches manages only plans valid
 * there. A new price applies to future sales only. See ADR 0006, ADR 0007
 * and ADR 0023.
 */
class PlanResource extends Resource
{
    use TranslatesItsLabels;

    protected static ?string $model = Plan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::GymSetup;

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Plan')
                    ->description('What members buy, as it shows at the desk, in the app and on receipts.')
                    ->icon(Heroicon::OutlinedTag)
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->placeholder('Monthly')
                            ->required()
                            ->maxLength(255)
                            ->rules(fn (?Plan $record): array => [self::nameNoOtherPlanHas($record)]),
                        TextInput::make('price')
                            ->required()
                            ->prefix('ETB')
                            ->placeholder('2,500')
                            ->live(onBlur: true)
                            ->helperText('A new price applies to future sales only.')
                            ->rules(fn (): array => [new ValidMoneyAmount]),
                        Textarea::make('description')
                            ->placeholder('Gym floor and group classes, any time the branch is open.')
                            ->maxLength(1000)
                            ->rows(2)
                            ->autosize()
                            ->columnSpanFull(),
                    ]),
                Section::make('Length and limits')
                    ->description('How long a membership of this plan runs, and what it allows.')
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->columns(['default' => 1, 'md' => 4])
                    ->schema([
                        TextInput::make('duration_count')
                            ->label('Lasts')
                            ->required()
                            ->integer()
                            ->minValue(1)
                            ->placeholder('1')
                            ->live(onBlur: true)
                            ->rules(fn (Get $get): array => ['max:'.Plan::longestDurationIn(DurationUnit::tryFrom((string) $get('duration_unit')) ?? DurationUnit::Day)]),
                        Select::make('duration_unit')
                            ->label('Counted in')
                            ->required()
                            ->native(false)
                            ->default(DurationUnit::Month->value)
                            ->options([
                                DurationUnit::Day->value => 'Days',
                                DurationUnit::Month->value => 'Months',
                            ])
                            ->live(),
                        TextInput::make('freeze_days_allowed')
                            ->label('Freeze days')
                            ->integer()
                            ->suffix('days')
                            ->default(0)
                            ->minValue(0)
                            ->maxValue(Plan::MOST_FREEZE_DAYS)
                            ->helperText('In total, over the membership.'),
                        TextInput::make('visit_limit')
                            ->label('Visits')
                            ->integer()
                            ->suffix('visits')
                            ->minValue(1)
                            ->maxValue(Plan::MOST_VISITS)
                            ->placeholder('No limit')
                            ->helperText('For a visit pack.'),
                        TextEntry::make('preview')
                            ->label('Bought today')
                            ->columnSpanFull()
                            ->icon(Heroicon::OutlinedCalendar)
                            ->color('gray')
                            ->state(fn (Get $get): string => self::previewOf($get('price'), $get('duration_count'), $get('duration_unit'))),
                    ]),
                Section::make('Where it is sold')
                    ->icon(Heroicon::OutlinedBuildingStorefront)
                    ->schema([
                        Select::make('branch_ids')
                            ->label('Branches')
                            ->multiple()
                            ->native(false)
                            ->options(fn (): array => app(CurrentStaff::class)->get()->branchesWorkedAt()->pluck('name', 'id')->all())
                            ->required(fn (): bool => ! app(CurrentStaff::class)->get()->works_at_all_branches)
                            ->placeholder('Every branch')
                            ->helperText('Leave empty to sell it at every branch. A membership is valid where its plan was sold.'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return Tables::standard($table, Heroicon::OutlinedTag, 'No plans yet', 'Add the plans you sell, such as Monthly or a 10-visit pack.')
            ->defaultSort('price_minor')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('branches'))
            ->searchPlaceholder('Plan name')
            ->columns([
                TextColumn::make('name')
                    ->weight(FontWeight::SemiBold)
                    ->description(fn (Plan $record): ?string => $record->description === null ? null : str($record->description)->limit(60)->toString())
                    ->searchable()
                    ->sortable(),
                Tables::money('price', fn (Plan $record) => $record->price)->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('price_minor', $direction)),
                TextColumn::make('duration')
                    ->badge()
                    ->color('gray')
                    ->state(fn (Plan $record): string => self::durationOf($record)),
                TextColumn::make('visit_limit')
                    ->label('Visits')
                    ->badge()
                    ->color(fn (?int $state): string => $state === null ? 'gray' : 'info')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? 'No limit' : "{$state}-visit pack")
                    ->placeholder('No limit'),
                TextColumn::make('freeze_days_allowed')
                    ->label('Freeze days')
                    ->numeric()
                    ->alignEnd()
                    ->formatStateUsing(fn (int $state): string => $state === 0 ? 'None' : "{$state} days"),
                TextColumn::make('branches_list')
                    ->label('Sold at')
                    ->badge()
                    ->color(fn (Plan $record): string => $record->available_at_all_branches ? 'primary' : 'gray')
                    ->state(fn (Plan $record): array => $record->available_at_all_branches ? ['Every branch'] : $record->branches->sortBy('name')->pluck('name')->all()),
            ])
            ->filters([
                SelectFilter::make('branch')
                    ->label('Sold at')
                    ->native(false)
                    ->options(fn (): array => self::onlyTheirBranchesQuery()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->where(fn (Builder $query): Builder => $query
                            ->where('available_at_all_branches', true)
                            ->orWhereHas('branches', fn (Builder $branches): Builder => $branches->whereKey((int) $data['value'])))
                        : $query),
                TernaryFilter::make('visit_pack')
                    ->label('Visit packs')
                    ->native(false)
                    ->attribute('visit_limit')
                    ->nullable()
                    ->placeholder('All plans')
                    ->trueLabel('Only visit packs')
                    ->falseLabel('No visit packs'),
                SelectFilter::make('duration_unit')
                    ->label('Counted in')
                    ->native(false)
                    ->options([DurationUnit::Day->value => 'Days', DurationUnit::Month->value => 'Months']),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlans::route('/'),
            'create' => CreatePlan::route('/create'),
            'edit' => EditPlan::route('/{record}/edit'),
        ];
    }

    /**
     * Save the plan from the form, and where it is sold: at the branches
     * chosen, or at every branch when none are, which only staff at all
     * branches may choose. As PlanController does for the API.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public static function savePlan(Plan $plan, array $data): Plan
    {
        $staff = app(CurrentStaff::class)->get();
        $branchIds = array_map('intval', (array) ($data['branch_ids'] ?? []));

        if (! $staff->worksAtEach($branchIds === [] ? null : $branchIds)) {
            throw ValidationException::withMessages(['data.branch_ids' => 'Choose branches you work at.']);
        }

        return DB::transaction(function () use ($plan, $data, $branchIds): Plan {
            $plan->fill([
                ...Arr::except($data, ['branch_ids', 'price', 'visit_limit']),
                'price' => Money::of((string) $data['price']),
                'visit_limit' => filled($data['visit_limit'] ?? null) ? (int) $data['visit_limit'] : null,
            ])->save();

            if ($branchIds === []) {
                $plan->makeAvailableAtAllBranches();
            } else {
                $plan->makeAvailableOnlyAt(...Branch::query()->whereKey($branchIds)->get()->all());
            }

            return $plan;
        });
    }

    public static function archiveAction(): Action
    {
        return Action::make('archive')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Archive this plan?')
            ->modalDescription('It is no longer on sale. Memberships already sold from it carry on.')
            ->visible(fn (Plan $record): bool => Gate::allows('delete', $record))
            ->action(function (Plan $record, Action $action): void {
                $record->delete();

                Notification::make()->success()->title('Plan archived.')->send();

                $action->redirect(self::getUrl('index'));
            });
    }

    /**
     * "Runs from 20 Nov 2026 to 19 Dec 2026, about 83.33 ETB a day", from the
     * form as it is being filled in.
     */
    private static function previewOf(mixed $price, mixed $count, mixed $unit): string
    {
        $durationUnit = DurationUnit::tryFrom((string) $unit);

        if (! is_numeric($count) || (int) $count < 1 || $durationUnit === null || (int) $count > Plan::longestDurationIn($durationUnit)) {
            return 'Fill in how long it lasts to see the dates.';
        }

        $plan = new Plan(['duration_count' => (int) $count, 'duration_unit' => $durationUnit]);
        $today = app(CurrentOrganization::class)->get()->today();
        $endsOn = $plan->endsOnFor($today);
        $days = (int) $today->diffInDays($endsOn) + 1;
        $preview = "Runs from {$today->translatedFormat('j M Y')} to {$endsOn->translatedFormat('j M Y')}, {$days} days";

        try {
            $perDay = (float) Money::of(is_string($price) ? $price : '')->toDecimal() / $days;
        } catch (InvalidMoneyAmount) {
            return "{$preview}.";
        }

        return "{$preview}, about ".number_format($perDay, 2).' ETB a day.';
    }

    private static function durationOf(Plan $plan): string
    {
        return $plan->duration_count.' '.Str::plural($plan->duration_unit === DurationUnit::Month ? 'month' : 'day', $plan->duration_count);
    }

    /**
     * The staff member's branches, for the branch filter.
     *
     * @return Builder<Branch>
     */
    private static function onlyTheirBranchesQuery(): Builder
    {
        $branchIds = app(CurrentStaff::class)->get()->limitedToBranchIds();

        return $branchIds === null ? Branch::query() : Branch::query()->whereKey($branchIds);
    }

    private static function nameNoOtherPlanHas(?Plan $ignoring): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ignoring): void {
            if (is_string($value) && Plan::nameTakenBy($value, $ignoring) !== null) {
                $fail('Another plan of this gym already has this name.');
            }
        };
    }
}
