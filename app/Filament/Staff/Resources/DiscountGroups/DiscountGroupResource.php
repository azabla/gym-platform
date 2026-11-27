<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\DiscountGroups;

use App\Domains\Memberships\Discount\Discount;
use App\Domains\Memberships\Discount\Exceptions\InvalidDiscount;
use App\Domains\Memberships\Models\DiscountGroup;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Filament\Staff\NavigationGroup;
use App\Filament\Staff\Resources\DiscountGroups\Pages\CreateDiscountGroup;
use App\Filament\Staff\Resources\DiscountGroups\Pages\EditDiscountGroup;
use App\Filament\Staff\Resources\DiscountGroups\Pages\ListDiscountGroups;
use App\Filament\Staff\Support\Tables;
use App\Filament\Staff\Support\TranslatesItsLabels;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * The gym's discount groups, such as Student 10%, with the API's rules: a
 * name no other active group has, and a discount staff could type at the
 * desk. Members are put in a group from their page. See ADR 0032.
 */
class DiscountGroupResource extends Resource
{
    use TranslatesItsLabels;

    protected static ?string $model = DiscountGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::GymSetup;

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Discount group')
                    ->description('Members in the group pay less in the app and at the desk, or a promo code\'s price when it is lower. Put members in it from their page.')
                    ->icon(Heroicon::OutlinedReceiptPercent)
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->placeholder('Student')
                            ->required()
                            ->maxLength(100)
                            ->helperText('Such as Student or Staff family.')
                            ->rules(fn (?DiscountGroup $record): array => [self::nameNoOtherGroupHas($record)]),
                        TextInput::make('discount')
                            ->placeholder('10% or 500')
                            ->required()
                            ->maxLength(20)
                            ->live(onBlur: true)
                            ->helperText(fn (Get $get): string => self::exampleOf((string) $get('discount')))
                            ->rules(fn (): array => [self::readableDiscount()]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return Tables::standard($table, Heroicon::OutlinedReceiptPercent, 'No discount groups yet', 'Make a group such as Student 10%, then put members in it from their page.')
            ->defaultSort('name')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('memberDiscounts'))
            ->searchPlaceholder('Group name')
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight(FontWeight::Bold),
                TextColumn::make('discount')->badge()->color('primary')->formatStateUsing(fn (string $state): string => "{$state} off"),
                TextColumn::make('member_discounts_count')
                    ->label('Members')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'info' : 'gray')
                    ->sortable(),
                TextColumn::make('example')
                    ->label('Monthly for them')
                    ->state(fn (DiscountGroup $record): string => self::exampleOf($record->discount, short: true))
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('deleted_at')->label('Archived')->date('j M Y')->badge()->color('gray')->placeholder('No'),
            ])
            ->filters([
                TrashedFilter::make()->label('Archived')->native(false),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    /**
     * Archived groups can be found and restored.
     *
     * @return Builder<DiscountGroup>
     */
    public static function getEloquentQuery(): Builder
    {
        return DiscountGroup::query()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDiscountGroups::route('/'),
            'create' => CreateDiscountGroup::route('/create'),
            'edit' => EditDiscountGroup::route('/{record}/edit'),
        ];
    }

    /**
     * Save the group from the form, as DiscountGroupController does for the API.
     *
     * @param  array<string, mixed>  $data
     */
    public static function saveGroup(DiscountGroup $discountGroup, array $data): DiscountGroup
    {
        $discountGroup->fill([
            'name' => trim((string) $data['name']),
            'discount' => trim((string) $data['discount']),
        ]);

        if (! $discountGroup->exists) {
            $staff = app(CurrentStaff::class)->get();
            $discountGroup->organization()->associate($staff->organization_id);
            $discountGroup->createdBy()->associate($staff);
        }

        $discountGroup->save();

        return $discountGroup;
    }

    public static function archiveAction(): Action
    {
        return Action::make('archive')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Archive this discount group?')
            ->modalDescription('Its members pay full price again, though they stay in it. Memberships already sold keep their price, and the group can be restored.')
            ->visible(fn (DiscountGroup $record): bool => ! $record->trashed() && Gate::allows('delete', $record))
            ->action(function (DiscountGroup $record): void {
                $record->delete();

                Notification::make()->success()->title('Discount group archived.')->send();
            });
    }

    public static function restoreAction(): Action
    {
        return Action::make('restore')
            ->requiresConfirmation()
            ->visible(fn (DiscountGroup $record): bool => $record->trashed() && Gate::allows('restore', $record))
            ->action(function (DiscountGroup $record): void {
                $record->restore();

                Notification::make()->success()->title('Discount group restored.')->send();
            });
    }

    /**
     * What the discount makes of the Monthly plan (or the dearest plan), as an
     * example: "On Monthly: 2,250.00 ETB instead of 2,500.00 ETB".
     */
    private static function exampleOf(string $typed, bool $short = false): string
    {
        $hint = 'An amount, such as 500, or a percentage, such as 10%. A change applies to future purchases.';
        $plan = Plan::query()->where('name', 'Monthly')->first() ?? Plan::query()->orderByDesc('price_minor')->first();

        if ($plan === null || trim($typed) === '') {
            return $short ? '—' : $hint;
        }

        try {
            $price = Discount::parse($typed, 'Example')->appliedTo($plan->price);
        } catch (InvalidDiscount) {
            return $short ? '—' : $hint;
        }

        return $short ? $price->format() : "On {$plan->name}: {$price->format()} instead of {$plan->price->format()}.";
    }

    private static function nameNoOtherGroupHas(?DiscountGroup $ignoring): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ignoring): void {
            if (is_string($value) && DiscountGroup::nameTakenBy($value, $ignoring) !== null) {
                $fail('Another discount group of this gym already has this name.');
            }
        };
    }

    private static function readableDiscount(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            try {
                Discount::parse((string) $value, 'Discount group');
            } catch (InvalidDiscount $exception) {
                $fail($exception->getMessage());
            }
        };
    }
}
