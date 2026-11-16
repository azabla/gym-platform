<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\PromoCodes;

use App\Domains\Memberships\Discount\Discount;
use App\Domains\Memberships\Discount\Exceptions\InvalidDiscount;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Memberships\Models\PromoCode;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Filament\Staff\Resources\PromoCodes\Pages\CreatePromoCode;
use App\Filament\Staff\Resources\PromoCodes\Pages\EditPromoCode;
use App\Filament\Staff\Resources\PromoCodes\Pages\ListPromoCodes;
use App\Http\Requests\Api\V1\PromoCodes\StorePromoCodeRequest;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The gym's promo codes, with the API's rules: a code no other has (archived
 * ones included), a discount staff could type at the desk, first and last
 * days, a total number of uses, once per member, and plans a limited manager
 * manages. A used code keeps its text. See ADR 0030.
 */
class PromoCodeResource extends Resource
{
    protected static ?string $model = PromoCode::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Promo code')
                    ->columns(2)
                    ->schema([
                        TextInput::make('code')
                            ->required()
                            ->regex(PromoCode::PATTERN)
                            ->validationMessages(['regex' => 'A code is 3 to 30 letters, digits or dashes, like NEWYEAR or GYM-2027.'])
                            ->helperText('What members type, such as NEWYEAR. Capitals don\'t matter.')
                            ->disabled(fn (?PromoCode $record): bool => $record !== null && $record->usesCount() > 0)
                            ->rules(fn (?PromoCode $record): array => [self::codeNoOtherHas($record)]),
                        TextInput::make('discount')
                            ->required()
                            ->maxLength(20)
                            ->helperText('An amount, such as 500, or a percentage, such as 20%.')
                            ->rules(fn (): array => [self::readableDiscount()]),
                        DatePicker::make('starts_on')->label('First day')->helperText('Leave empty to start now.'),
                        DatePicker::make('ends_on')->label('Last day')->afterOrEqual('starts_on')->helperText('Leave empty for no end.'),
                        TextInput::make('max_uses')
                            ->label('Total uses')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(StorePromoCodeRequest::MOST_USES)
                            ->helperText('For example, the first 50 members. Leave empty for no limit.'),
                        Toggle::make('once_per_member')->label('Once per member')->default(true)->inline(false),
                        Select::make('plan_ids')
                            ->label('Plans')
                            ->multiple()
                            ->columnSpanFull()
                            ->options(fn (): array => self::plansTheyManage())
                            ->required(fn (): bool => ! app(CurrentStaff::class)->get()->works_at_all_branches)
                            ->helperText('Leave empty for every plan.'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->defaultPaginationPageOption(25)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('plans')->withCount('memberships'))
            ->columns([
                TextColumn::make('code')->searchable()->weight('bold'),
                TextColumn::make('discount'),
                TextColumn::make('status')
                    ->badge()
                    ->state(fn (PromoCode $record): string => self::statusOf($record))
                    ->color(fn (string $state): string => $state === 'Active' ? 'success' : ($state === 'Upcoming' ? 'warning' : 'gray')),
                TextColumn::make('dates')
                    ->state(fn (PromoCode $record): string => self::datesOf($record)),
                TextColumn::make('uses')
                    ->state(fn (PromoCode $record): string => $record->max_uses === null
                        ? (string) $record->memberships_count
                        : "{$record->memberships_count} of {$record->max_uses}"),
                TextColumn::make('plans_list')
                    ->label('Plans')
                    ->state(fn (PromoCode $record): string => $record->available_for_all_plans ? 'All plans' : $record->plans->sortBy('name')->pluck('name')->join(', ')),
            ])
            ->filters([
                TrashedFilter::make()->label('Archived'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    /**
     * Archived codes can be found and restored.
     *
     * @return Builder<PromoCode>
     */
    public static function getEloquentQuery(): Builder
    {
        return PromoCode::query()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPromoCodes::route('/'),
            'create' => CreatePromoCode::route('/create'),
            'edit' => EditPromoCode::route('/{record}/edit'),
        ];
    }

    /**
     * Save the code from the form, and its plans: those chosen, or every plan
     * when none are, which a manager limited to some branches may not choose.
     * As PromoCodeController does for the API.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public static function saveCode(PromoCode $promoCode, array $data): PromoCode
    {
        $planIds = array_values(array_map('intval', (array) ($data['plan_ids'] ?? [])));

        if (! Gate::allows('createFor', [PromoCode::class, $planIds === [] ? null : $planIds])) {
            throw ValidationException::withMessages(['data.plan_ids' => 'Choose plans sold only at branches you work at.']);
        }

        return DB::transaction(function () use ($promoCode, $data, $planIds): PromoCode {
            $staff = app(CurrentStaff::class)->get();

            $promoCode->fill([
                'discount' => trim((string) $data['discount']),
                'starts_on' => $data['starts_on'] ?? null,
                'ends_on' => $data['ends_on'] ?? null,
                'max_uses' => filled($data['max_uses'] ?? null) ? (int) $data['max_uses'] : null,
                'once_per_member' => (bool) ($data['once_per_member'] ?? true),
            ]);

            if (array_key_exists('code', $data)) {
                $promoCode->code = (string) $data['code'];
            }

            if (! $promoCode->exists) {
                $promoCode->organization()->associate($staff->organization_id);
                $promoCode->createdBy()->associate($staff);
            }

            $promoCode->save();

            if ($planIds === []) {
                $promoCode->makeAvailableForAllPlans();
            } else {
                $promoCode->makeAvailableOnlyFor(...Plan::query()->whereKey($planIds)->get()->all());
            }

            return $promoCode;
        });
    }

    public static function archiveAction(): Action
    {
        return Action::make('archive')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Archive this promo code?')
            ->modalDescription('It can\'t be used any more, in the app or at the desk. Memberships sold with it keep their price, and it can be restored.')
            ->visible(fn (PromoCode $record): bool => ! $record->trashed() && Gate::allows('delete', $record))
            ->action(function (PromoCode $record): void {
                $record->delete();

                Notification::make()->success()->title('Promo code archived.')->send();
            });
    }

    public static function restoreAction(): Action
    {
        return Action::make('restore')
            ->requiresConfirmation()
            ->visible(fn (PromoCode $record): bool => $record->trashed() && Gate::allows('restore', $record))
            ->action(function (PromoCode $record): void {
                $record->restore();

                Notification::make()->success()->title('Promo code restored.')->send();
            });
    }

    /**
     * Where the code stands today, in the gym's time zone.
     */
    public static function statusOf(PromoCode $promoCode): string
    {
        $today = app(CurrentOrganization::class)->get()->today();
        $uses = $promoCode->memberships_count ?? $promoCode->usesCount();

        return match (true) {
            $promoCode->trashed() => 'Archived',
            $promoCode->ends_on !== null && $today->greaterThan($promoCode->ends_on) => 'Ended',
            $promoCode->max_uses !== null && $uses >= $promoCode->max_uses => 'Used up',
            $promoCode->starts_on !== null && $today->lessThan($promoCode->starts_on) => 'Upcoming',
            default => 'Active',
        };
    }

    private static function datesOf(PromoCode $promoCode): string
    {
        return match (true) {
            $promoCode->starts_on === null && $promoCode->ends_on === null => 'Any time',
            $promoCode->starts_on === null => 'Until '.$promoCode->ends_on->format('j M Y'),
            $promoCode->ends_on === null => 'From '.$promoCode->starts_on->format('j M Y'),
            default => $promoCode->starts_on->format('j M Y').' to '.$promoCode->ends_on->format('j M Y'),
        };
    }

    /**
     * The plans on sale that the staff member manages: for a manager limited
     * to some branches, those sold only at their branches (ADR 0023).
     *
     * @return array<int, string>
     */
    private static function plansTheyManage(): array
    {
        $staff = app(CurrentStaff::class)->get();

        return Plan::query()->with('branches')->orderBy('name')->get()
            ->filter(fn (Plan $plan): bool => $staff->worksAtEach($plan->limitedToBranchIds()))
            ->pluck('name', 'id')
            ->all();
    }

    private static function codeNoOtherHas(?PromoCode $ignoring): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ignoring): void {
            if (is_string($value) && PromoCode::codeTakenBy($value, $ignoring) !== null) {
                $fail('This gym already has this code, perhaps archived. Choose another.');
            }
        };
    }

    private static function readableDiscount(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            try {
                Discount::parse((string) $value, 'Promo code');
            } catch (InvalidDiscount $exception) {
                $fail($exception->getMessage());
            }
        };
    }
}
