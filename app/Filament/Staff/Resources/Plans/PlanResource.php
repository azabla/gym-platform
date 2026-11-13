<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\Plans;

use App\Domains\Memberships\Enums\DurationUnit;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Domains\Shared\Money\Money;
use App\Filament\Staff\Resources\Plans\Pages\CreatePlan;
use App\Filament\Staff\Resources\Plans\Pages\EditPlan;
use App\Filament\Staff\Resources\Plans\Pages\ListPlans;
use App\Rules\ValidMoneyAmount;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The plans the gym sells, with the API's rules: a name no other plan has,
 * a length of up to 366 days or 36 months, and branches the staff member
 * works at. A manager limited to some branches manages only plans valid
 * there. A new price applies to future sales only. See ADR 0006, ADR 0007
 * and ADR 0022.
 */
class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Plan')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->rules(fn (?Plan $record): array => [self::nameNoOtherPlanHas($record)]),
                        TextInput::make('price')
                            ->required()
                            ->suffix('ETB')
                            ->rules(fn (): array => [new ValidMoneyAmount]),
                        Textarea::make('description')->maxLength(1000)->columnSpanFull(),
                        TextInput::make('duration_count')
                            ->label('Lasts')
                            ->required()
                            ->integer()
                            ->minValue(1)
                            ->rules(fn (Get $get): array => ['max:'.Plan::longestDurationIn(DurationUnit::tryFrom((string) $get('duration_unit')) ?? DurationUnit::Day)]),
                        Select::make('duration_unit')
                            ->label('Counted in')
                            ->required()
                            ->default(DurationUnit::Month->value)
                            ->options([
                                DurationUnit::Day->value => 'Days',
                                DurationUnit::Month->value => 'Months',
                            ])
                            ->live(),
                        TextInput::make('freeze_days_allowed')
                            ->label('Freeze days')
                            ->integer()
                            ->default(0)
                            ->minValue(0)
                            ->maxValue(Plan::MOST_FREEZE_DAYS),
                        TextInput::make('visit_limit')
                            ->label('Visits')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(Plan::MOST_VISITS)
                            ->helperText('For a visit pack. Leave empty for no limit.'),
                        Select::make('branch_ids')
                            ->label('Branches')
                            ->multiple()
                            ->columnSpanFull()
                            ->options(fn (): array => app(CurrentStaff::class)->get()->branchesWorkedAt()->pluck('name', 'id')->all())
                            ->required(fn (): bool => ! app(CurrentStaff::class)->get()->works_at_all_branches)
                            ->helperText('Leave empty to sell it at every branch.'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->defaultPaginationPageOption(25)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('branches'))
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('price')->state(fn (Plan $record): string => $record->price->format()),
                TextColumn::make('duration')
                    ->state(fn (Plan $record): string => $record->duration_count.' '.Str::plural($record->duration_unit === DurationUnit::Month ? 'month' : 'day', $record->duration_count)),
                TextColumn::make('freeze_days_allowed')->label('Freeze days'),
                TextColumn::make('visit_limit')->label('Visits')->placeholder('No limit'),
                TextColumn::make('branches_list')
                    ->label('Branches')
                    ->state(fn (Plan $record): string => $record->available_at_all_branches ? 'All branches' : $record->branches->sortBy('name')->pluck('name')->join(', ')),
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

    private static function nameNoOtherPlanHas(?Plan $ignoring): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ignoring): void {
            if (is_string($value) && Plan::nameTakenBy($value, $ignoring) !== null) {
                $fail('Another plan of this gym already has this name.');
            }
        };
    }
}
