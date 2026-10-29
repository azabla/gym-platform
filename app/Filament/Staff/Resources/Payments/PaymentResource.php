<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\Payments;

use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Enums\PaymentType;
use App\Domains\Billing\Models\Payment;
use App\Domains\Shared\Money\Money;
use App\Filament\Staff\Resources\Concerns\ShowsGymTime;
use App\Filament\Staff\Resources\Payments\Pages\ListPayments;
use App\Filament\Staff\Resources\Payments\Pages\ViewPayment;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Payments and refunds, as the API's payments list shows them, with what the
 * filtered payments add up to. Trainers don't handle money, so the payment
 * policy keeps this from them. Taking, refunding and voiding payments stay in
 * the app for now. See ADR 0008 and ADR 0017.
 */
class PaymentResource extends Resource
{
    use ShowsGymTime;

    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 3;

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Payment')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('receipt_number'),
                        TextEntry::make('type')->formatStateUsing(fn (PaymentType $state): string => ucfirst($state->value)),
                        TextEntry::make('membership.member.full_name')->label('Member'),
                        TextEntry::make('membership.plan.name')->label('Plan'),
                        TextEntry::make('amount')->state(fn (Payment $record): string => $record->amount->format()),
                        TextEntry::make('method')->formatStateUsing(fn (PaymentMethod $state): string => self::methodLabel($state)),
                        TextEntry::make('reference')->placeholder('None'),
                        TextEntry::make('paid_on')->date(),
                        TextEntry::make('branch.name')->label('Branch'),
                        TextEntry::make('receivedBy.user.name')->label('Received by'),
                        TextEntry::make('voided_at')->label('Voided')->dateTime()->timezone(self::gymTimezone())->placeholder('No'),
                        TextEntry::make('void_reason')->placeholder('None'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('paid_on', 'desc')
            ->defaultPaginationPageOption(25)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['membership.member', 'branch']))
            ->description(fn (HasTable $livewire): string => self::totalsOf($livewire->getFilteredTableQuery()))
            ->columns([
                TextColumn::make('receipt_number')->label('Receipt'),
                TextColumn::make('paid_on')->date()->sortable(),
                TextColumn::make('membership.member.full_name')->label('Member'),
                TextColumn::make('type')->formatStateUsing(fn (PaymentType $state): string => ucfirst($state->value)),
                TextColumn::make('amount')->state(fn (Payment $record): string => $record->amount->format()),
                TextColumn::make('method')->formatStateUsing(fn (PaymentMethod $state): string => self::methodLabel($state)),
                TextColumn::make('branch.name')->label('Branch'),
                TextColumn::make('voided_at')->label('Voided')->date()->timezone(self::gymTimezone())->placeholder('No'),
            ])
            ->filters([
                Filter::make('paid_on')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('to'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $from): Builder => $query->where('paid_on', '>=', $from))
                        ->when($data['to'] ?? null, fn (Builder $query, string $to): Builder => $query->where('paid_on', '<=', $to))),
                SelectFilter::make('branch')->relationship('branch', 'name'),
                SelectFilter::make('method')->options(collect(PaymentMethod::cases())->mapWithKeys(
                    fn (PaymentMethod $method): array => [$method->value => self::methodLabel($method)],
                )->all()),
                SelectFilter::make('member')
                    ->relationship('member', 'full_name')
                    ->searchable(),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayments::route('/'),
            'view' => ViewPayment::route('/{record}'),
        ];
    }

    public static function methodLabel(PaymentMethod $method): string
    {
        return match ($method) {
            PaymentMethod::Cash => 'Cash',
            PaymentMethod::Telebirr => 'telebirr',
            PaymentMethod::CbeBirr => 'CBE Birr',
            PaymentMethod::BankTransfer => 'Bank transfer',
            PaymentMethod::Chapa => 'Chapa',
        };
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

        return "Received {$received->format()}, refunded {$refunded->format()}, net {$net->format()}";
    }
}
