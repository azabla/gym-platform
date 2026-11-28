<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\ExpenseCategories;

use App\Domains\Billing\Models\ExpenseCategory;
use App\Filament\Staff\NavigationGroup;
use App\Filament\Staff\Resources\ExpenseCategories\Pages\ListExpenseCategories;
use App\Filament\Staff\Support\Tables;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * What the gym spends money on (ADR 0047): the starting list, shown in each
 * reader's language until renamed, and the gym's own. Owners and
 * accountants add, rename and archive them.
 */
class ExpenseCategoryResource extends Resource
{
    protected static ?string $model = ExpenseCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::GymSetup;

    protected static ?int $navigationSort = 6;

    public static function getModelLabel(): string
    {
        return __('expense category');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Expense categories');
    }

    public static function getNavigationLabel(): string
    {
        return __('Expense categories');
    }

    public static function table(Table $table): Table
    {
        return Tables::standard($table, Heroicon::OutlinedTag, __('No expense categories'), __('Add the things the gym spends money on with New expense category.'))
            ->defaultSort('id')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->state(fn (ExpenseCategory $record): string => $record->displayName())
                    ->weight('semibold'),
                TextColumn::make('kind')
                    ->label(__('From'))
                    ->state(fn (ExpenseCategory $record): string => $record->default_key === null ? __('Added by the gym') : __('Starting list'))
                    ->badge()
                    ->color(fn (ExpenseCategory $record): string => $record->default_key === null ? 'primary' : 'gray'),
                TextColumn::make('deleted_at')->label(__('Archived'))->date('j M Y')->placeholder(__('No'))->toggleable(),
            ])
            ->filters([
                TrashedFilter::make()->label(__('Archived'))->native(false),
            ])
            ->recordActions([
                self::renameAction(),
                Action::make('archive')->label(__('Archive'))
                    ->icon(Heroicon::OutlinedArchiveBox)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(__('It can no longer be chosen for new expenses. Expenses already recorded keep it.'))
                    ->visible(fn (ExpenseCategory $record): bool => ! $record->trashed() && Gate::allows('delete', $record))
                    ->action(function (ExpenseCategory $record): void {
                        $record->delete();
                        Notification::make()->success()->title(__('Category archived.'))->send();
                    }),
                Action::make('restore')->label(__('Restore'))
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->visible(fn (ExpenseCategory $record): bool => $record->trashed() && Gate::allows('restore', $record))
                    ->action(function (ExpenseCategory $record): void {
                        $record->restore();
                        Notification::make()->success()->title(__('Category restored.'))->send();
                    }),
            ]);
    }

    /**
     * @return Builder<ExpenseCategory>
     */
    public static function getEloquentQuery(): Builder
    {
        return ExpenseCategory::query()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenseCategories::route('/'),
        ];
    }

    public static function createAction(): CreateAction
    {
        return CreateAction::make()
            ->label(__('New expense category'))
            ->modalHeading(__('New expense category'))
            ->schema([self::nameInput(null)])
            ->using(function (array $data): ExpenseCategory {
                $category = new ExpenseCategory;
                $category->forceFill(['name' => trim((string) $data['name'])])->save();

                return $category;
            });
    }

    private static function renameAction(): Action
    {
        return Action::make('rename')->label(__('Rename'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->visible(fn (ExpenseCategory $record): bool => ! $record->trashed() && Gate::allows('update', $record))
            ->fillForm(fn (ExpenseCategory $record): array => ['name' => $record->displayName()])
            ->schema(fn (ExpenseCategory $record): array => [self::nameInput($record)])
            ->action(function (ExpenseCategory $record, array $data): void {
                $record->rename((string) $data['name']);
                Notification::make()->success()->title(__('Category renamed.'))->send();
            });
    }

    private static function nameInput(?ExpenseCategory $record): TextInput
    {
        return TextInput::make('name')
            ->label(__('Name'))
            ->placeholder(__('Water for members'))
            ->required()
            ->maxLength(100)
            ->rules(fn (): array => [self::nameNoOtherCategoryHas($record)]);
    }

    private static function nameNoOtherCategoryHas(?ExpenseCategory $record): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($record): void {
            if (ExpenseCategory::nameTakenBy((string) $value, $record) !== null) {
                $fail(__('Another category already has this name.'));
            }
        };
    }
}
