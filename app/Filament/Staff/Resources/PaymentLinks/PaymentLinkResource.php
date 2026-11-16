<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\PaymentLinks;

use App\Domains\Billing\Enums\PaymentLinkStatus;
use App\Domains\Billing\Models\PaymentLink;
use App\Filament\Staff\Resources\Concerns\LimitsToTheStaffMembersBranches;
use App\Filament\Staff\Resources\Concerns\ShowsGymTime;
use App\Filament\Staff\Resources\PaymentLinks\Pages\ListPaymentLinks;
use App\Filament\Staff\Resources\PaymentLinks\Pages\ViewPaymentLink;
use App\Filament\Staff\Resources\Payments\PaymentResource;
use BackedEnum;
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
 * Links to Chapa's payment page, made at the desk or by members in the app,
 * as the API's payment links list shows them. A pending link's checkout
 * address is there to copy and send; Check now asks Chapa whether it was
 * paid, and Cancel withdraws it. Trainers don't handle money, so the payment
 * link policy keeps this from them. See ADR 0009 and ADR 0029.
 */
class PaymentLinkResource extends Resource
{
    use LimitsToTheStaffMembersBranches;
    use ShowsGymTime;

    protected static ?string $model = PaymentLink::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static ?int $navigationSort = 3;

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Payment link')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('member.full_name')->label('Member'),
                        TextEntry::make('for')->state(fn (PaymentLink $record): string => self::whatItIsFor($record)),
                        TextEntry::make('amount')->state(fn (PaymentLink $record): string => $record->amount->format()),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (PaymentLinkStatus $state): string => self::statusLabel($state))
                            ->color(fn (PaymentLinkStatus $state): string => self::statusColor($state)),
                        TextEntry::make('checkout_url')
                            ->label('Link to send the member')
                            ->copyable()
                            ->copyMessage('Copied')
                            ->columnSpanFull()
                            ->visible(fn (PaymentLink $record): bool => $record->status === PaymentLinkStatus::Pending),
                        TextEntry::make('branch.name')->label('Branch'),
                        TextEntry::make('createdBy.user.name')->label('Created by')->placeholder('The member, in the app'),
                        TextEntry::make('created_at')->label('Created')->dateTime()->timezone(self::gymTimezone()),
                        TextEntry::make('payment.receipt_number')
                            ->label('Receipt')
                            ->placeholder('None')
                            ->url(fn (PaymentLink $record): ?string => $record->payment_id === null ? null : PaymentResource::getUrl('view', ['record' => $record->payment_id])),
                        TextEntry::make('chapa_reference')->label('Chapa reference')->placeholder('None'),
                        TextEntry::make('cancelled_at')->label('Cancelled')->dateTime()->timezone(self::gymTimezone())->placeholder('No'),
                        TextEntry::make('sms_sent_at')->label('Texted to the member')->dateTime()->timezone(self::gymTimezone())->placeholder('Not yet'),
                        TextEntry::make('refunded_at')
                            ->label('Refunded')
                            ->dateTime()
                            ->timezone(self::gymTimezone())
                            ->visible(fn (PaymentLink $record): bool => $record->refunded_at !== null),
                        TextEntry::make('refundedBy.user.name')
                            ->label('Refunded by')
                            ->visible(fn (PaymentLink $record): bool => $record->refunded_at !== null),
                        TextEntry::make('refund_note')
                            ->label('Refund note')
                            ->placeholder('None')
                            ->visible(fn (PaymentLink $record): bool => $record->refunded_at !== null),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(25)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['member', 'plan', 'membership.plan', 'branch', 'createdBy.user']))
            ->columns([
                TextColumn::make('created_at')->label('Created')->dateTime()->timezone(self::gymTimezone())->sortable(),
                TextColumn::make('member.full_name')->label('Member'),
                TextColumn::make('for')->state(fn (PaymentLink $record): string => self::whatItIsFor($record)),
                TextColumn::make('amount')->state(fn (PaymentLink $record): string => $record->amount->format()),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (PaymentLinkStatus $state): string => self::statusLabel($state))
                    ->color(fn (PaymentLinkStatus $state): string => self::statusColor($state)),
                TextColumn::make('branch.name')->label('Branch'),
                TextColumn::make('createdBy.user.name')->label('Created by')->placeholder('In the app'),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(PaymentLinkStatus::cases())->mapWithKeys(
                    fn (PaymentLinkStatus $status): array => [$status->value => self::statusLabel($status)],
                )->all()),
                SelectFilter::make('branch')->relationship('branch', 'name', self::onlyTheirBranches(...)),
                SelectFilter::make('member')
                    ->relationship('member', 'full_name')
                    ->searchable(),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    /**
     * Only the links of the staff member's branches: another branch's link
     * isn't listed or opened (ADR 0025). Link queries are already limited to
     * the current gym (ADR 0003).
     *
     * @return Builder<PaymentLink>
     */
    public static function getEloquentQuery(): Builder
    {
        return PaymentLink::query()->forAnyOf(self::staffBranchIds());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentLinks::route('/'),
            'view' => ViewPaymentLink::route('/{record}'),
        ];
    }

    public static function statusLabel(PaymentLinkStatus $status): string
    {
        return match ($status) {
            PaymentLinkStatus::Pending => 'Waiting',
            PaymentLinkStatus::Paid => 'Paid',
            PaymentLinkStatus::Cancelled => 'Cancelled',
            PaymentLinkStatus::RefundNeeded => 'Refund needed',
            PaymentLinkStatus::Refunded => 'Refunded',
        };
    }

    private static function statusColor(PaymentLinkStatus $status): string
    {
        return match ($status) {
            PaymentLinkStatus::Pending => 'warning',
            PaymentLinkStatus::Paid => 'success',
            PaymentLinkStatus::Cancelled => 'gray',
            PaymentLinkStatus::RefundNeeded => 'danger',
            PaymentLinkStatus::Refunded => 'gray',
        };
    }

    /**
     * A membership being paid, or a plan being bought in the app.
     */
    private static function whatItIsFor(PaymentLink $paymentLink): string
    {
        return $paymentLink->isForAPlan() ? "Buying {$paymentLink->planName()}" : $paymentLink->planName();
    }
}
