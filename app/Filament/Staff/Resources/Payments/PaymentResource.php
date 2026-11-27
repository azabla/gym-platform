<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\Payments;

use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Enums\PaymentType;
use App\Domains\Billing\Models\Payment;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Shared\Money\Money;
use App\Filament\Staff\NavigationGroup;
use App\Filament\Staff\Resources\Concerns\LimitsToTheStaffMembersBranches;
use App\Filament\Staff\Resources\Concerns\ShowsGymTime;
use App\Filament\Staff\Resources\Members\MemberResource;
use App\Filament\Staff\Resources\Memberships\MembershipResource;
use App\Filament\Staff\Resources\Payments\Pages\ListPayments;
use App\Filament\Staff\Resources\Payments\Pages\ViewPayment;
use App\Filament\Staff\Support\Badges;
use App\Filament\Staff\Support\Dates;
use App\Filament\Staff\Support\Tables;
use App\Filament\Staff\Support\TranslatesItsLabels;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Payments and refunds, as the API's payments list shows them, with what the
 * filtered payments add up to. Trainers don't handle money, so the payment
 * policy keeps this from them. Payments are taken, refunded and voided from a
 * membership's or a payment's page. See ADR 0008, ADR 0017 and ADR 0033.
 */
class PaymentResource extends Resource
{
    use LimitsToTheStaffMembersBranches;
    use ShowsGymTime;
    use TranslatesItsLabels;

    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Money;

    protected static ?int $navigationSort = 1;

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Receipt'))
                    ->icon(Heroicon::OutlinedReceiptPercent)
                    ->schema([
                        Grid::make(['default' => 2, 'lg' => 4])->schema([
                            TextEntry::make('amount')->label(__('Amount'))
                                ->state(fn (Payment $record): string => $record->amount->format())
                                ->size('lg')
                                ->weight(FontWeight::Bold)
                                ->color(fn (Payment $record): string => $record->type === PaymentType::Refund ? 'danger' : 'success'),
                            TextEntry::make('type')->label(__('Type'))
                                ->badge()
                                ->formatStateUsing(fn (PaymentType $state): string => Badges::label($state))
                                ->color(fn (PaymentType $state): string => Badges::color($state))
                                ->icon(fn (PaymentType $state): ?Heroicon => Badges::icon($state)),
                            TextEntry::make('receipt_number')->label(__('Receipt'))->copyable()->weight(FontWeight::SemiBold),
                            TextEntry::make('voided_at')
                                ->label(__('Voided'))
                                ->formatStateUsing(Dates::asDateTime(self::gymTimezone()))
                                ->badge()
                                ->color('danger')
                                ->placeholder(__('No')),
                        ]),
                    ]),
                Section::make(__('Payment'))
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->columns(['default' => 1, 'md' => 3])
                    ->schema([
                        TextEntry::make('membership.member.full_name')
                            ->label(__('Member'))
                            ->weight(FontWeight::SemiBold)
                            ->url(fn (Payment $record): ?string => $record->membership?->member_id === null ? null : MemberResource::getUrl('view', ['record' => $record->membership->member_id])),
                        TextEntry::make('membership.plan.name')
                            ->label(__('Membership'))
                            ->badge()
                            ->color('primary')
                            ->url(fn (Payment $record): string => MembershipResource::getUrl('view', ['record' => $record->membership_id])),
                        TextEntry::make('method')->label(__('Method'))
                            ->badge()
                            ->formatStateUsing(fn (PaymentMethod $state): string => Badges::label($state))
                            ->color(fn (PaymentMethod $state): string => Badges::color($state)),
                        TextEntry::make('reference')->label(__('Transaction number'))->copyable()->placeholder(__('None')),
                        TextEntry::make('paid_on')->label(__('Paid on'))->formatStateUsing(Dates::asDate()),
                        TextEntry::make('branch.name')->label(__('Branch'))->badge()->color('gray'),
                        TextEntry::make('receivedBy.user.name')->label(__('Taken by'))->placeholder(__('Online, through Chapa')),
                        TextEntry::make('void_reason')
                            ->label(__('Why it was voided'))
                            ->columnSpan(2)
                            ->visible(fn (Payment $record): bool => $record->voided_at !== null),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return Tables::standard($table, Heroicon::OutlinedBanknotes, __('No payments here'), __('Take a payment from a membership\'s page, or change the filters.'))
            ->defaultSort('paid_on', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['membership.member', 'membership.plan', 'branch', 'receivedBy.user']))
            ->description(fn (HasTable $livewire): string => self::totalsOf($livewire->getFilteredTableQuery()))
            ->searchPlaceholder(__('Receipt, transaction number or member'))
            ->columns([
                TextColumn::make('receipt_number')
                    ->label(__('Receipt'))
                    ->weight(FontWeight::SemiBold)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('paid_on')->label(__('Paid on'))->formatStateUsing(Dates::asDate())->sortable(),
                TextColumn::make('membership.member.full_name')
                    ->label(__('Member'))
                    ->description(fn (Payment $record): string => (string) $record->membership?->plan?->name)
                    ->searchable(),
                Tables::badge('type'),
                Tables::money('amount', fn (Payment $record): Money => $record->amount)
                    ->color(fn (Payment $record): string => match (true) {
                        $record->voided_at !== null => 'gray',
                        $record->type === PaymentType::Refund => 'danger',
                        default => 'success',
                    })
                    ->extraAttributes(fn (Payment $record): array => ['class' => 'tabular-nums'.($record->voided_at !== null ? ' line-through' : '')]),
                Tables::badge('method'),
                TextColumn::make('reference')->label(__('Transaction number'))->placeholder(__('None'))->searchable()->copyable()->toggleable(),
                TextColumn::make('branch.name')->label(__('Branch'))->badge()->color('gray'),
                TextColumn::make('receivedBy.user.name')->label(__('Taken by'))->placeholder(__('Online'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('voided_at')
                    ->label(__('Voided'))
                    ->formatStateUsing(Dates::asDate(self::gymTimezone()))
                    ->badge()
                    ->color('danger')
                    ->placeholder(__('No'))
                    ->toggleable(),
            ])
            ->filters([
                Tables::dateRange('paid_on', 'paid_on', __('Paid')),
                SelectFilter::make('branch')->label(__('Branch'))->native(false)->relationship('branch', 'name', self::onlyTheirBranches(...)),
                SelectFilter::make('method')->label(__('Method'))->native(false)->options(Badges::options(PaymentMethod::class)),
                SelectFilter::make('type')->label(__('Type'))->native(false)->options(Badges::options(PaymentType::class)),
                TernaryFilter::make('voided')->label(__('Voided'))
                    ->native(false)
                    ->attribute('voided_at')
                    ->nullable()
                    ->placeholder(__('Voided or not'))
                    ->trueLabel(__('Only voided'))
                    ->falseLabel(__('Not voided')),
                SelectFilter::make('received_by')
                    ->label(__('Taken by'))
                    ->native(false)
                    ->options(fn (): array => Staff::query()->withTrashed()->with('user')->get()
                        ->mapWithKeys(fn (Staff $staff): array => [$staff->id => (string) $staff->user?->name])
                        ->sort()
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->where('received_by_staff_id', (int) $data['value'])
                        : $query),
                SelectFilter::make('member')->label(__('Member'))
                    ->native(false)
                    ->relationship('member', 'full_name')
                    ->searchable(),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    /**
     * Only the payments taken at the staff member's branches: another
     * branch's payment isn't listed, counted or opened (ADR 0025). Payment
     * queries are already limited to the current gym (ADR 0003).
     *
     * @return Builder<Payment>
     */
    public static function getEloquentQuery(): Builder
    {
        return Payment::query()->takenAtAnyOf(self::staffBranchIds());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayments::route('/'),
            'view' => ViewPayment::route('/{record}'),
        ];
    }

    /**
     * What the filtered payments add up to, as the API's payments list adds
     * them up: voided payments don't count.
     *
     * @param  Builder<Payment>|null  $payments
     */
    private static function totalsOf(?Builder $payments): string
    {
        if ($payments === null) {
            return '';
        }

        $totals = Payment::totalsOf($payments);

        /** @var Money $received */
        $received = $totals['received'];
        /** @var Money $refunded */
        $refunded = $totals['refunded'];
        /** @var Money $net */
        $net = $totals['net'];
        $line = __('Received :received, refunded :refunded, net :net', ['received' => $received->format(), 'refunded' => $refunded->format(), 'net' => $net->format()]);

        // Money brought in by an import counts for balances, not as money taken (ADR 0044).
        return $totals['brought_in']->isZero()
            ? $line
            : $line.' · '.__('brought in :amount, not counted as money taken', ['amount' => $totals['brought_in']->format()]);
    }
}
