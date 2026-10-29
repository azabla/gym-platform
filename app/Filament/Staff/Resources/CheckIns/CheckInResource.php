<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\CheckIns;

use App\Domains\Attendance\Enums\CheckInDenial;
use App\Domains\Attendance\Enums\CheckInMethod;
use App\Domains\Attendance\Enums\CheckInResult;
use App\Domains\Attendance\Models\CheckIn;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Filament\Staff\Resources\CheckIns\Pages\ListCheckIns;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Who came in, and who was turned away and why, newest first, with times
 * where the gym is. Checking members in stays in the app. See ADR 0011 and
 * ADR 0017.
 */
class CheckInResource extends Resource
{
    protected static ?string $model = CheckIn::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowRightEndOnRectangle;

    protected static ?int $navigationSort = 4;

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('checked_in_at', 'desc')
            ->defaultPaginationPageOption(25)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['member', 'branch']))
            ->columns([
                TextColumn::make('checked_in_at')->label('When')->dateTime()->timezone(self::gym()->timezone)->sortable(),
                TextColumn::make('member.full_name')->label('Member')->placeholder('Unknown'),
                TextColumn::make('branch.name')->label('Branch'),
                TextColumn::make('method')->formatStateUsing(fn (CheckInMethod $state): string => $state === CheckInMethod::Qr ? 'QR code' : 'At the desk'),
                TextColumn::make('result')
                    ->badge()
                    ->color(fn (CheckInResult $state): string => $state === CheckInResult::Allowed ? 'success' : 'danger')
                    ->formatStateUsing(fn (CheckInResult $state): string => ucfirst($state->value)),
                TextColumn::make('reason_code')
                    ->label('Reason')
                    ->formatStateUsing(fn (CheckInDenial $state): string => self::reasonLabel($state)),
            ])
            ->filters([
                Filter::make('on')
                    ->schema([
                        DatePicker::make('on')->label('Day'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['on'] ?? null,
                        fn (Builder $query, string $day): Builder => self::onDay($query, CarbonImmutable::parse($day)),
                    )),
                SelectFilter::make('branch')->relationship('branch', 'name'),
                SelectFilter::make('result')->options([
                    CheckInResult::Allowed->value => 'Allowed',
                    CheckInResult::Denied->value => 'Denied',
                ]),
                SelectFilter::make('member')
                    ->relationship('member', 'full_name')
                    ->searchable(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCheckIns::route('/'),
        ];
    }

    /**
     * Why a member was turned away, in words: MEMBERSHIP_EXPIRED reads
     * "Membership expired".
     */
    public static function reasonLabel(CheckInDenial $reason): string
    {
        return Str::of($reason->value)->lower()->replace('_', ' ')->ucfirst()->toString();
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
