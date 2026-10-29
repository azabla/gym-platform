<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\Memberships;

use App\Domains\Billing\Models\Payment;
use App\Domains\Memberships\Enums\MembershipStatus;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\MembershipFreeze;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Filament\Staff\Resources\Memberships\Pages\ListMemberships;
use App\Filament\Staff\Resources\Memberships\Pages\ViewMembership;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The gym's memberships, to look up: selling, freezing and cancelling stay in
 * the app for now. A membership's status is worked out for the gym's today, as
 * in the API. See ADR 0017.
 */
class MembershipResource extends Resource
{
    protected static ?string $model = Membership::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?int $navigationSort = 2;

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Membership')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('member.full_name')->label('Member'),
                        TextEntry::make('plan.name')->label('Plan'),
                        TextEntry::make('status')
                            ->badge()
                            ->state(fn (Membership $record): string => self::statusOf($record)->value)
                            ->formatStateUsing(fn (string $state): string => ucfirst($state)),
                        TextEntry::make('starts_on')->date(),
                        TextEntry::make('ends_on')->date(),
                        TextEntry::make('visit_limit')->label('Visits')->placeholder('No limit'),
                        TextEntry::make('freeze_days_left')
                            ->state(fn (Membership $record): string => max(0, $record->freeze_days_allowed - $record->freezeDaysUsed())." of {$record->freeze_days_allowed}"),
                        TextEntry::make('freezes')
                            ->state(fn (Membership $record): array => $record->freezes()->orderBy('starts_on')->get()->map(
                                fn (MembershipFreeze $freeze): string => "{$freeze->starts_on->format('j M Y')} to {$freeze->ends_on->format('j M Y')}: {$freeze->reason}",
                            )->all())
                            ->listWithLineBreaks()
                            ->placeholder('None'),
                        TextEntry::make('price')->state(fn (Membership $record): string => $record->price->format()),
                        TextEntry::make('amount_paid')
                            ->state(fn (Membership $record): string => Payment::balancesOf([$record])[$record->id]->amountPaid->format()),
                        TextEntry::make('balance_due')
                            ->state(fn (Membership $record): string => Payment::balancesOf([$record])[$record->id]->balanceDue->format()),
                        TextEntry::make('cancelled_at')->dateTime()->placeholder('Not cancelled'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('starts_on', 'desc')
            ->defaultPaginationPageOption(25)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['member', 'plan', 'freezes']))
            ->columns([
                TextColumn::make('member.full_name')->label('Member')->searchable(),
                TextColumn::make('plan.name')->label('Plan'),
                TextColumn::make('starts_on')->date()->sortable(),
                TextColumn::make('ends_on')->date()->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->state(fn (Membership $record): string => self::statusOf($record)->value)
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),
                TextColumn::make('price')->state(fn (Membership $record): string => $record->price->format()),
            ])
            ->filters([
                SelectFilter::make('member')
                    ->relationship('member', 'full_name')
                    ->searchable(),
                SelectFilter::make('status')
                    ->options(collect(MembershipStatus::cases())->mapWithKeys(
                        fn (MembershipStatus $status): array => [$status->value => ucfirst($status->value)],
                    )->all())
                    ->query(fn (Builder $query, array $data): Builder => self::withStatus($query, $data['value'] ?? null)),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMemberships::route('/'),
            'view' => ViewMembership::route('/{record}'),
        ];
    }

    private static function statusOf(Membership $membership): MembershipStatus
    {
        return $membership->statusOn(self::today());
    }

    /**
     * Narrow to the memberships with the status on the gym's today, as
     * Membership::statusOn() works it out.
     *
     * @param  Builder<Membership>  $query
     * @return Builder<Membership>
     */
    private static function withStatus(Builder $query, ?string $status): Builder
    {
        $today = self::today()->toDateString();
        $frozenToday = fn ($freezes) => $freezes->where('starts_on', '<=', $today)->where('ends_on', '>=', $today);
        $current = fn (Builder $query): Builder => $query->whereNull('cancelled_at')
            ->where('starts_on', '<=', $today)
            ->where('ends_on', '>=', $today);

        return match (MembershipStatus::tryFrom((string) $status)) {
            null => $query,
            MembershipStatus::Cancelled => $query->whereNotNull('cancelled_at'),
            MembershipStatus::Upcoming => $query->whereNull('cancelled_at')->where('starts_on', '>', $today),
            MembershipStatus::Expired => $query->whereNull('cancelled_at')->where('ends_on', '<', $today),
            MembershipStatus::Frozen => $current($query)->whereHas('freezes', $frozenToday),
            MembershipStatus::Active => $current($query)->whereDoesntHave('freezes', $frozenToday),
        };
    }

    private static function today(): CarbonImmutable
    {
        return app(CurrentOrganization::class)->get()->today();
    }
}
