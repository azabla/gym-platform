<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\Branches;

use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\BranchOpeningHour;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Filament\Staff\Resources\Branches\Pages\CreateBranch;
use App\Filament\Staff\Resources\Branches\Pages\EditBranch;
use App\Filament\Staff\Resources\Branches\Pages\ListBranches;
use BackedEnum;
use Closure;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * The gym's branches: owners add them, and owners and managers who work at
 * a branch change its details and opening hours, in the gym's time zone. A
 * branch without hours is always open. See ADR 0016 and ADR 0022.
 */
class BranchResource extends Resource
{
    protected static ?string $model = Branch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?int $navigationSort = 6;

    /** @var array<int, string> */
    private const WEEKDAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Branch')
                    ->columns(3)
                    ->schema([
                        TextInput::make('name')->required()->maxLength(255),
                        TextInput::make('code')
                            ->required()
                            ->helperText('A short code, such as BOLE.')
                            ->rules(fn (?Branch $record): array => [self::validCodeNoOtherBranchHas($record)]),
                        TextInput::make('address')->maxLength(255),
                    ]),
                Section::make('Opening hours')
                    ->description('In the gym\'s time zone. With no hours the branch is always open; once it has some, a day without hours is closed.')
                    ->schema([
                        Repeater::make('hours')
                            ->hiddenLabel()
                            ->addActionLabel('Add a day')
                            ->maxItems(7)
                            ->columns(3)
                            ->defaultItems(0)
                            ->schema([
                                Select::make('weekday')
                                    ->options(self::WEEKDAYS)
                                    ->required()
                                    ->distinct()
                                    ->validationMessages(['distinct' => 'Give each weekday once.']),
                                TimePicker::make('opens_at')->label('Opens')->seconds(false)->required(),
                                TimePicker::make('closes_at')
                                    ->label('Closes')
                                    ->seconds(false)
                                    ->required()
                                    ->after('opens_at')
                                    ->validationMessages(['after' => 'Closing must be after opening, on the same day.']),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('openingHours'))
            ->columns([
                TextColumn::make('name')->sortable(),
                TextColumn::make('code'),
                TextColumn::make('address')->placeholder('None'),
                TextColumn::make('hours')
                    ->label('Opening hours')
                    ->state(fn (Branch $record): array => self::hoursOf($record))
                    ->listWithLineBreaks(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBranches::route('/'),
            'create' => CreateBranch::route('/create'),
            'edit' => EditBranch::route('/{record}/edit'),
        ];
    }

    /**
     * Only the branches the staff member works at. Branch queries are
     * already limited to the current gym (ADR 0003).
     *
     * @return Builder<Branch>
     */
    public static function getEloquentQuery(): Builder
    {
        $branchIds = app(CurrentStaff::class)->get()->limitedToBranchIds();

        return Branch::query()->when($branchIds !== null, fn (Builder $query) => $query->whereKey($branchIds));
    }

    /**
     * Save the branch's details and its whole week of opening hours.
     *
     * @param  array<string, mixed>  $data
     */
    public static function saveBranch(Branch $branch, array $data): Branch
    {
        return DB::transaction(function () use ($branch, $data): Branch {
            $branch->fill(Arr::only($data, ['name', 'code', 'address']))->save();

            /** @var list<array{weekday: int|string, opens_at: string, closes_at: string}> $hours */
            $hours = array_values((array) ($data['hours'] ?? []));

            $branch->replaceOpeningHours(array_map(fn (array $day): array => [
                'weekday' => (int) $day['weekday'],
                'opens_at' => substr($day['opens_at'], 0, 5).':00',
                'closes_at' => substr($day['closes_at'], 0, 5).':00',
            ], $hours));

            return $branch;
        });
    }

    /**
     * The branch's week as the form shows it.
     *
     * @return list<array{weekday: int, opens_at: string, closes_at: string}>
     */
    public static function weekOf(Branch $branch): array
    {
        return $branch->openingHours->map(fn (BranchOpeningHour $day): array => [
            'weekday' => $day->weekday,
            'opens_at' => substr((string) $day->opens_at, 0, 5),
            'closes_at' => substr((string) $day->closes_at, 0, 5),
        ])->values()->all();
    }

    /**
     * @return list<string>
     */
    private static function hoursOf(Branch $branch): array
    {
        if ($branch->openingHours->isEmpty()) {
            return ['Always open'];
        }

        return array_map(
            fn (array $day): string => self::WEEKDAYS[$day['weekday']]." {$day['opens_at']}–{$day['closes_at']}",
            self::weekOf($branch),
        );
    }

    private static function validCodeNoOtherBranchHas(?Branch $ignoring): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ignoring): void {
            $code = Branch::tidyCode(is_string($value) ? $value : '');

            if (preg_match(Branch::CODE_PATTERN, $code) !== 1) {
                $fail('A code is 2 to 20 letters, digits or hyphens, such as BOLE.');
            } elseif (Branch::codeTakenBy($code, $ignoring) !== null) {
                $fail('Another branch of this gym already has this code.');
            }
        };
    }
}
