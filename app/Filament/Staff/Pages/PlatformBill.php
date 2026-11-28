<?php

declare(strict_types=1);

namespace App\Filament\Staff\Pages;

use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Domains\PlatformBilling\GymBills;
use App\Domains\PlatformBilling\Models\GymInvoice;
use App\Domains\PlatformBilling\Models\GymSubscription;
use App\Domains\Shared\Money\Money;
use App\Filament\Staff\NavigationGroup;
use App\Filament\Staff\Support\Dates;
use App\Filament\Staff\Support\Tables;
use App\Filament\Staff\Support\TranslatesItsTitle;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * What the gym pays the platform, for its owners only (ADR 0045): its plan,
 * its next invoice, its invoices and how to pay them.
 */
class PlatformBill extends Page implements HasTable
{
    use InteractsWithTable;
    use TranslatesItsTitle;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCurrencyDollar;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::GymSetup;

    protected static ?int $navigationSort = 8;

    protected static ?string $title = 'Platform bill';

    /** @var array<string, string> */
    private const STATUS_COLORS = ['due' => 'warning', 'overdue' => 'danger', 'paid' => 'success', 'voided' => 'gray'];

    public static function canAccess(): bool
    {
        return app(CurrentStaff::class)->get()->role === StaffRole::Owner;
    }

    public function content(Schema $schema): Schema
    {
        $gym = self::gym();
        $subscription = GymSubscription::of($gym);
        $status = app(GymBills::class)->statusOf($gym);

        return $schema->components([
            Section::make(__('Your plan'))
                ->description($subscription === null
                    ? __('The gym isn\'t billed by the platform yet.')
                    : __('Prices include VAT. Each invoice is issued a week before its period and is due on its first day.'))
                ->icon(Heroicon::OutlinedRectangleStack)
                ->columns(['default' => 1, 'md' => 3])
                ->schema($subscription === null ? [] : [
                    TextEntry::make('status')->label(__('Status'))->state(__($status->label()))->badge()->color(self::statusColor($status->value)),
                    TextEntry::make('plan')->label(__('Plan'))->state(self::planLine($subscription))->weight(FontWeight::SemiBold),
                    TextEntry::make('months')->label(__('Each invoice covers'))->state(trans_choice(':count month|:count months', $subscription->months_per_invoice)),
                    TextEntry::make('trial')
                        ->label(__('Free trial until'))
                        ->state($subscription->trial_ends_on === null ? null : Dates::long($subscription->trial_ends_on))
                        ->visible($subscription->isOnTrial($gym->today())),
                    TextEntry::make('next')
                        ->label(__('Next invoice'))
                        ->state(self::nextInvoiceLine($subscription))
                        ->columnSpan(['md' => 2]),
                ]),
            Section::make(__('How to pay'))
                ->description(__('Pay into one of these accounts with the invoice number as the reason. The platform records your payment and the invoice shows it as paid.'))
                ->icon(Heroicon::OutlinedBanknotes)
                ->visible($subscription !== null)
                ->schema([
                    TextEntry::make('pay_to')
                        ->label(__('How to pay'))
                        ->hiddenLabel()
                        ->state(self::payTo())
                        ->listWithLineBreaks()
                        ->bulleted()
                        ->placeholder(__('Ask the platform how to pay.')),
                ]),
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return Tables::standard($table, Heroicon::OutlinedDocumentText, __('No invoices yet'), __('Invoices from the platform show here once they are issued.'))
            ->query(fn (): Builder => GymInvoice::query()->withSum('payments as paid_minor', 'amount_minor'))
            ->heading(__('Invoices'))
            ->defaultSort('number', 'desc')
            ->columns([
                TextColumn::make('number')
                    ->label(__('Invoice'))
                    ->formatStateUsing(fn (GymInvoice $record): string => $record->reference())
                    ->weight(FontWeight::SemiBold)
                    ->fontFamily('mono')
                    ->sortable(),
                TextColumn::make('period')
                    ->label(__('Period'))
                    ->state(fn (GymInvoice $record): string => Dates::short($record->period_starts_on).' – '.Dates::short($record->period_ends_on))
                    ->color('gray'),
                Tables::money('total', fn (GymInvoice $record): Money => $record->total, __('Total')),
                Tables::money('to_pay', fn (GymInvoice $record): Money => $record->isOpen() ? $record->total->subtract(Money::ofMinor((int) $record->getAttribute('paid_minor'), $record->total->currency)) : Money::zero(), __('Still to pay')),
                TextColumn::make('due_on')->label(__('Due on'))->formatStateUsing(fn (GymInvoice $record): string => Dates::short($record->due_on))->sortable(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->state(fn (GymInvoice $record): string => self::invoiceStatusOf($record))
                    ->formatStateUsing(fn (string $state): string => __(ucfirst($state)))
                    ->badge()
                    ->color(fn (string $state): string => self::STATUS_COLORS[$state]),
                TextColumn::make('pauses_on')
                    ->label(__('Pauses the gym on'))
                    ->formatStateUsing(fn (GymInvoice $record): string => $record->isOpen() ? Dates::short($record->pauses_on) : '—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->native(false)
                    ->options(['open' => __('Still to pay'), 'paid' => __('Paid'), 'voided' => __('Voided')])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'open' => $query->whereNull('paid_at')->whereNull('voided_at'),
                        'paid' => $query->whereNotNull('paid_at'),
                        'voided' => $query->whereNotNull('voided_at'),
                        default => $query,
                    }),
                Tables::dateRange('due', 'due_on', __('Due on')),
            ])
            ->recordActions([
                Action::make('view')
                    ->label(__('View'))
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->url(fn (GymInvoice $record): string => route('platform-bill.invoice', $record->getKey()))
                    ->openUrlInNewTab(),
            ]);
    }

    /**
     * The accounts gyms pay into, from the platform's configuration.
     *
     * @return list<string>
     */
    public static function payTo(): array
    {
        $accounts = config('platform.pay_to');

        return is_array($accounts) ? array_values(array_filter($accounts, 'is_string')) : [];
    }

    private static function invoiceStatusOf(GymInvoice $invoice): string
    {
        return match (true) {
            $invoice->voided_at !== null => 'voided',
            $invoice->paid_at !== null => 'paid',
            $invoice->isOverdueOn(self::gym()->today()) => 'overdue',
            default => 'due',
        };
    }

    private static function statusColor(string $status): string
    {
        return match ($status) {
            'trial' => 'info',
            'paid_up' => 'success',
            'due' => 'warning',
            'overdue', 'paused_unpaid' => 'danger',
            default => 'gray',
        };
    }

    private static function planLine(GymSubscription $subscription): string
    {
        $line = __(':plan, :price a month', ['plan' => $subscription->plan->name, 'price' => $subscription->monthlyPrice()->format()]);

        if (! $subscription->extraBranchPrice()->isZero()) {
            $line .= __(', :price for each extra branch', ['price' => $subscription->extraBranchPrice()->format()]);
        }

        return $line;
    }

    private static function nextInvoiceLine(GymSubscription $subscription): string
    {
        $charge = $subscription->chargeNow();

        return __('From :date: :total for :branches (VAT inside: :vat)', [
            'date' => Dates::long($subscription->next_period_starts_on),
            'total' => $charge->total->format(),
            'branches' => trans_choice(':count branch|:count branches', $charge->branches),
            'vat' => $charge->vat->format(),
        ]);
    }

    private static function gym(): Organization
    {
        return app(CurrentOrganization::class)->get();
    }
}
