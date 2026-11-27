<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\PromoCodes;

use App\Domains\Memberships\Discount\Discount;
use App\Domains\Memberships\Discount\Exceptions\InvalidDiscount;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Memberships\Models\PromoCode;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Filament\Staff\NavigationGroup;
use App\Filament\Staff\Resources\PromoCodes\Pages\CreatePromoCode;
use App\Filament\Staff\Resources\PromoCodes\Pages\EditPromoCode;
use App\Filament\Staff\Resources\PromoCodes\Pages\ListPromoCodes;
use App\Filament\Staff\Support\Badges;
use App\Filament\Staff\Support\Tables;
use App\Filament\Staff\Support\TranslatesItsLabels;
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
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * The gym's promo codes, with the API's rules: a code no other has (archived
 * ones included), a discount staff could type at the desk, first and last
 * days, a total number of uses, once per member, and plans a limited manager
 * manages. A used code keeps its text. See ADR 0030.
 */
class PromoCodeResource extends Resource
{
    use TranslatesItsLabels;

    protected static ?string $model = PromoCode::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::GymSetup;

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Code and discount')
                    ->description('Share the code on Telegram or a poster; members type it in the app, or give it at the desk.')
                    ->icon(Heroicon::OutlinedTicket)
                    ->columns(2)
                    ->schema([
                        TextInput::make('code')
                            ->placeholder('NEWYEAR')
                            ->prefixIcon(Heroicon::OutlinedHashtag)
                            ->required()
                            ->regex(PromoCode::PATTERN)
                            ->extraInputAttributes(['style' => 'text-transform: uppercase'])
                            ->validationMessages(['regex' => 'A code is 3 to 30 letters, digits or dashes, like NEWYEAR or GYM-2027.'])
                            ->helperText(fn (?PromoCode $record): string => $record !== null && $record->usesCount() > 0
                                ? 'It has been used, so its text stays. Archive it and make a new one to change it.'
                                : 'What members type. Capitals don\'t matter.')
                            ->disabled(fn (?PromoCode $record): bool => $record !== null && $record->usesCount() > 0)
                            ->rules(fn (?PromoCode $record): array => [self::codeNoOtherHas($record)]),
                        TextInput::make('discount')
                            ->placeholder('20% or 500')
                            ->required()
                            ->maxLength(20)
                            ->live(onBlur: true)
                            ->helperText(fn (Get $get): string => self::exampleOf((string) $get('discount')))
                            ->rules(fn (): array => [self::readableDiscount()]),
                    ]),
                Section::make('Limits')
                    ->description('All optional. A code without limits works for everyone, on every plan, until you archive it.')
                    ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        DatePicker::make('starts_on')->label('First day')->native(false)->displayFormat('j M Y')->placeholder('Starts now'),
                        DatePicker::make('ends_on')->label('Last day')->native(false)->displayFormat('j M Y')->afterOrEqual('starts_on')->placeholder('No end'),
                        TextInput::make('max_uses')
                            ->label('Total uses')
                            ->integer()
                            ->suffix('uses')
                            ->minValue(1)
                            ->maxValue(StorePromoCodeRequest::MOST_USES)
                            ->placeholder('No limit')
                            ->helperText('For example, the first 50 members.'),
                        Toggle::make('once_per_member')
                            ->label('Once per member')
                            ->helperText('Each member can use it once.')
                            ->default(true)
                            ->inline(false),
                        Select::make('plan_ids')
                            ->label('Plans')
                            ->multiple()
                            ->native(false)
                            ->columnSpanFull()
                            ->options(fn (): array => self::plansTheyManage())
                            ->required(fn (): bool => ! app(CurrentStaff::class)->get()->works_at_all_branches)
                            ->placeholder('Every plan')
                            ->helperText('Leave empty for every plan.'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return Tables::standard($table, Heroicon::OutlinedTicket, 'No promo codes yet', 'Make a code such as NEWYEAR for an offer, and share it with members.')
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('plans')->withCount('memberships'))
            ->searchPlaceholder('Code')
            ->columns([
                TextColumn::make('code')
                    ->searchable()
                    ->weight(FontWeight::Bold)
                    ->fontFamily('mono')
                    ->copyable()
                    ->copyMessage('Copied'),
                TextColumn::make('discount')->badge()->color('primary')->formatStateUsing(fn (string $state): string => "{$state} off"),
                TextColumn::make('status')
                    ->badge()
                    ->state(fn (PromoCode $record): string => self::statusOf($record))
                    ->color(fn (string $state): string => Badges::promoCodeColor($state)),
                TextColumn::make('dates')
                    ->state(fn (PromoCode $record): string => self::datesOf($record))
                    ->color('gray'),
                TextColumn::make('uses')
                    ->alignEnd()
                    ->weight(FontWeight::Medium)
                    ->state(fn (PromoCode $record): string => $record->max_uses === null
                        ? (string) $record->memberships_count
                        : "{$record->memberships_count} of {$record->max_uses}")
                    ->color(fn (PromoCode $record): string => match (true) {
                        $record->max_uses === null => 'gray',
                        $record->memberships_count >= $record->max_uses => 'danger',
                        $record->memberships_count >= 0.8 * $record->max_uses => 'warning',
                        default => 'success',
                    }),
                TextColumn::make('once_per_member')
                    ->label('Per member')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Once' : 'Any number')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('plans_list')
                    ->label('Plans')
                    ->badge()
                    ->color(fn (PromoCode $record): string => $record->available_for_all_plans ? 'primary' : 'gray')
                    ->state(fn (PromoCode $record): array => $record->available_for_all_plans ? ['All plans'] : $record->plans->sortBy('name')->pluck('name')->all()),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->native(false)
                    ->options(['active' => 'Active', 'upcoming' => 'Upcoming', 'ended' => 'Ended', 'used_up' => 'Used up'])
                    ->query(fn (Builder $query, array $data): Builder => self::withStatus($query, $data['value'] ?? null)),
                SelectFilter::make('plan')
                    ->native(false)
                    ->options(fn (): array => Plan::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->where(fn (Builder $query): Builder => $query
                            ->where('available_for_all_plans', true)
                            ->orWhereHas('plans', fn (Builder $plans): Builder => $plans->whereKey((int) $data['value'])))
                        : $query),
                TrashedFilter::make()->label('Archived')->native(false),
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

    /**
     * Narrow to the codes that stand so today, as statusOf() words it.
     *
     * @param  Builder<PromoCode>  $query
     * @return Builder<PromoCode>
     */
    private static function withStatus(Builder $query, mixed $status): Builder
    {
        $today = app(CurrentOrganization::class)->get()->today()->toDateString();
        $usedUp = 'max_uses IS NOT NULL AND max_uses <= (SELECT count(*) FROM memberships WHERE memberships.promo_code_id = promo_codes.id)';

        return match ($status) {
            'ended' => $query->where('ends_on', '<', $today),
            'used_up' => $query->whereRaw($usedUp),
            'upcoming' => $query->where('starts_on', '>', $today),
            'active' => $query->whereNull('deleted_at')
                ->where(fn (Builder $query): Builder => $query->whereNull('starts_on')->orWhere('starts_on', '<=', $today))
                ->where(fn (Builder $query): Builder => $query->whereNull('ends_on')->orWhere('ends_on', '>=', $today))
                ->whereRaw("NOT ({$usedUp})"),
            default => $query,
        };
    }

    /**
     * What the discount takes off a plan, as an example: "20% off Monthly:
     * 2,000.00 ETB instead of 2,500.00 ETB".
     */
    private static function exampleOf(string $typed): string
    {
        $plan = Plan::query()->where('name', 'Monthly')->first() ?? Plan::query()->orderByDesc('price_minor')->first();

        if ($plan === null || trim($typed) === '') {
            return 'An amount, such as 500, or a percentage, such as 20%.';
        }

        try {
            $price = Discount::parse($typed, 'Example')->appliedTo($plan->price);
        } catch (InvalidDiscount) {
            return 'An amount, such as 500, or a percentage, such as 20%.';
        }

        return "On {$plan->name}: {$price->format()} instead of {$plan->price->format()}.";
    }

    private static function datesOf(PromoCode $promoCode): string
    {
        return match (true) {
            $promoCode->starts_on === null && $promoCode->ends_on === null => 'Any time',
            $promoCode->starts_on === null => 'Until '.$promoCode->ends_on->translatedFormat('j M Y'),
            $promoCode->ends_on === null => 'From '.$promoCode->starts_on->translatedFormat('j M Y'),
            default => $promoCode->starts_on->translatedFormat('j M Y').' to '.$promoCode->ends_on->translatedFormat('j M Y'),
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
