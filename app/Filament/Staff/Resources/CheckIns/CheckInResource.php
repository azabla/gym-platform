<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\CheckIns;

use App\Domains\Attendance\Enums\CheckInDenial;
use App\Domains\Attendance\Enums\CheckInMethod;
use App\Domains\Attendance\Enums\CheckInResult;
use App\Domains\Attendance\Models\CheckIn;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Filament\Staff\NavigationGroup;
use App\Filament\Staff\Resources\CheckIns\Pages\ListCheckIns;
use App\Filament\Staff\Resources\Concerns\LimitsToTheStaffMembersBranches;
use App\Filament\Staff\Resources\Members\MemberResource;
use App\Filament\Staff\Support\Badges;
use App\Filament\Staff\Support\Photos;
use App\Filament\Staff\Support\Tables;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Who came in, and who was turned away and why, newest first, with times
 * where the gym is. Members are checked in from their page or the list of
 * members. See ADR 0011, ADR 0017 and ADR 0033.
 */
class CheckInResource extends Resource
{
    use LimitsToTheStaffMembersBranches;

    protected static ?string $model = CheckIn::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowRightEndOnRectangle;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::FrontDesk;

    protected static ?int $navigationSort = 3;

    public static function table(Table $table): Table
    {
        return Tables::standard($table, Heroicon::OutlinedArrowRightEndOnRectangle, 'No check-ins here', 'Check members in from their page, or change the filters.')
            ->defaultSort('checked_in_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['member', 'branch', 'checkedInBy.user']))
            ->searchPlaceholder('Member name')
            ->columns([
                TextColumn::make('checked_in_at')
                    ->label('When')
                    ->dateTime('j M Y, H:i')
                    ->timezone(self::gym()->timezone)
                    ->description(fn (CheckIn $record): string => $record->checked_in_at->diffForHumans())
                    ->sortable(),
                ImageColumn::make('photo')
                    ->label('')
                    ->state(fn (CheckIn $record): string => Photos::urlFor($record->member))
                    ->circular()
                    ->imageSize(32),
                TextColumn::make('member.full_name')
                    ->label('Member')
                    ->weight(FontWeight::SemiBold)
                    ->placeholder('Unknown')
                    ->searchable()
                    ->url(fn (CheckIn $record): ?string => $record->member_id === null ? null : MemberResource::getUrl('view', ['record' => $record->member_id])),
                Tables::badge('result')
                    ->description(fn (CheckIn $record): ?string => $record->reason_code?->label()),
                TextColumn::make('branch.name')->label('Branch')->badge()->color('gray'),
                Tables::badge('method'),
                TextColumn::make('reason_code')
                    ->label('Reason')
                    ->formatStateUsing(fn (CheckInDenial $state): string => $state->label())
                    ->placeholder('None')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('checkedInBy.user.name')->label('By')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('on')
                    ->label('Day')
                    ->schema([
                        DatePicker::make('on')->label('Day')->native(false)->displayFormat('j M Y'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['on'] ?? null,
                        fn (Builder $query, string $day): Builder => self::onDay($query, CarbonImmutable::parse($day)),
                    ))
                    ->indicateUsing(fn (array $data): ?Indicator => filled($data['on'] ?? null)
                        ? Indicator::make('On '.CarbonImmutable::parse((string) $data['on'])->format('j M Y'))
                        : null),
                Tables::dateRange('between', 'checked_in_at', 'Between', isMoment: true),
                SelectFilter::make('branch')->native(false)->relationship('branch', 'name', self::onlyTheirBranches(...)),
                SelectFilter::make('result')->native(false)->options(Badges::options(CheckInResult::class)),
                SelectFilter::make('reason_code')
                    ->label('Reason')
                    ->native(false)
                    ->options(collect(CheckInDenial::cases())->mapWithKeys(
                        fn (CheckInDenial $denial): array => [$denial->value => $denial->label()],
                    )->all()),
                SelectFilter::make('method')->native(false)->options(Badges::options(CheckInMethod::class)),
                SelectFilter::make('member')
                    ->native(false)
                    ->relationship('member', 'full_name')
                    ->searchable(),
            ]);
    }

    /**
     * Only the check-ins made at the staff member's branches (ADR 0025).
     * Check-in queries are already limited to the current gym (ADR 0003).
     *
     * @return Builder<CheckIn>
     */
    public static function getEloquentQuery(): Builder
    {
        return CheckIn::query()->madeAtAnyOf(self::staffBranchIds());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCheckIns::route('/'),
        ];
    }

    /**
     * The check-ins on one day where the gym is, as the API's ?on= does.
     *
     * @param  Builder<CheckIn>  $query
     * @return Builder<CheckIn>
     */
    private static function onDay(Builder $query, CarbonImmutable $day): Builder
    {
        $start = CarbonImmutable::parse($day->toDateString(), self::gym()->timezone);

        return $query
            ->where('checked_in_at', '>=', $start->utc())
            ->where('checked_in_at', '<', $start->addDay()->utc());
    }

    private static function gym(): Organization
    {
        return app(CurrentOrganization::class)->get();
    }
}
