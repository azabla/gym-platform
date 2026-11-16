<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\DiscountGroups;

use App\Domains\Memberships\Discount\Discount;
use App\Domains\Memberships\Discount\Exceptions\InvalidDiscount;
use App\Domains\Memberships\Models\DiscountGroup;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Filament\Staff\NavigationGroup;
use App\Filament\Staff\Resources\DiscountGroups\Pages\CreateDiscountGroup;
use App\Filament\Staff\Resources\DiscountGroups\Pages\EditDiscountGroup;
use App\Filament\Staff\Resources\DiscountGroups\Pages\ListDiscountGroups;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
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
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * The gym's discount groups, such as Student 10%, with the API's rules: a
 * name no other active group has, and a discount staff could type at the
 * desk. Members are put in a group from their page. See ADR 0032.
 */
class DiscountGroupResource extends Resource
{
    protected static ?string $model = DiscountGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::GymSetup;

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Discount group')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(100)
                            ->helperText('Such as Student or Staff family.')
                            ->rules(fn (?DiscountGroup $record): array => [self::nameNoOtherGroupHas($record)]),
                        TextInput::make('discount')
                            ->required()
                            ->maxLength(20)
                            ->helperText('An amount, such as 500, or a percentage, such as 10%. A change applies to future purchases.')
                            ->rules(fn (): array => [self::readableDiscount()]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->defaultPaginationPageOption(25)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('memberDiscounts'))
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('bold'),
                TextColumn::make('discount'),
                TextColumn::make('member_discounts_count')->label('Members'),
                TextColumn::make('deleted_at')->label('Archived')->date()->placeholder('No'),
            ])
            ->filters([
                TrashedFilter::make()->label('Archived'),
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
