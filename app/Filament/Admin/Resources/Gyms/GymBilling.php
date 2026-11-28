<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Gyms;

use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\PlatformBilling\Enums\GymBillStatus;
use App\Domains\PlatformBilling\Exceptions\BillingRefused;
use App\Domains\PlatformBilling\GymBills;
use App\Domains\PlatformBilling\GymCharge;
use App\Domains\PlatformBilling\Models\GymSubscription;
use App\Domains\PlatformBilling\Models\PlatformPlan;
use App\Domains\PlatformBilling\SubscriptionTerms;
use App\Domains\Shared\Money\Exceptions\InvalidMoneyAmount;
use App\Domains\Shared\Money\Money;
use App\Rules\ValidMoneyAmount;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * A gym's billing in the admin panel (ADR 0045): setting it up, changing its
 * plan or prices, extending its trial, and the section that shows it.
 */
final class GymBilling
{
    public const STATUS_COLORS = [
        'not_billed' => 'gray',
        'trial' => 'info',
        'paid_up' => 'success',
        'due' => 'warning',
        'overdue' => 'danger',
        'paused_unpaid' => 'danger',
    ];

    public static function setUpAction(Organization $gym): Action
    {
        return Action::make('setUpBilling')
            ->label('Set up billing')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('primary')
            ->visible(fn (): bool => GymSubscription::of($gym) === null)
            ->modalIcon(Heroicon::OutlinedBanknotes)
            ->modalHeading(fn (): string => 'Bill '.$gym->name)
            ->modalDescription('Each invoice is issued 7 days before its period starts and is due on its first day. An unpaid invoice pauses the gym after its grace.')
            ->modalSubmitActionLabel('Start billing')
            ->schema([
                ...self::termsFields($gym, null),
                Radio::make('start')
                    ->label('Starts')
                    ->options(['trial' => 'After a free trial', 'date' => 'On a day I choose'])
                    ->default('trial')
                    ->inline()
                    ->live()
                    ->required(),
                TextInput::make('trial_days')
                    ->label('Free trial')
                    ->integer()
                    ->minValue(1)
                    ->maxValue(GymSubscription::MOST_TRIAL_DAYS)
                    ->default(GymSubscription::DEFAULT_TRIAL_DAYS)
                    ->suffix('days')
                    ->visible(fn (Get $get): bool => $get('start') === 'trial')
                    ->required(fn (Get $get): bool => $get('start') === 'trial'),
                DatePicker::make('first_billing_day')
                    ->label('First billing day')
                    ->native(false)
                    ->default(fn (): string => $gym->today()->toDateString())
                    ->minDate(fn (): string => $gym->today()->toDateString())
                    ->helperText('The first period starts on this day; its invoice is issued at once if the day is less than a week away.')
                    ->visible(fn (Get $get): bool => $get('start') === 'date')
                    ->required(fn (Get $get): bool => $get('start') === 'date'),
            ])
            ->action(function (array $data, Action $action) use ($gym): void {
                try {
                    GymSubscription::start(
                        $gym,
                        self::termsFrom($data),
                        $data['start'] === 'trial' ? (int) $data['trial_days'] : 0,
                        $data['start'] === 'date' ? CarbonImmutable::parse((string) $data['first_billing_day']) : null,
                    );
                } catch (BillingRefused $refused) {
                    Notification::make()->danger()->title($refused->getMessage())->send();
                    $action->halt();
                }

                Notification::make()->success()->title('Billing set up.')->send();
            });
    }

    public static function changeAction(Organization $gym): Action
    {
        return Action::make('changeBilling')
            ->label('Change plan')
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->color('gray')
            ->visible(fn (): bool => GymSubscription::of($gym) !== null)
            ->modalIcon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->modalHeading(fn (): string => 'Change how '.$gym->name.' is billed')
            ->modalDescription('It applies from the next invoice. Invoices already issued stay as they are; void one to issue it again.')
            ->fillForm(fn (): array => self::formStateOf(GymSubscription::of($gym)))
            ->schema(fn (): array => self::termsFields($gym, GymSubscription::of($gym)))
            ->action(function (array $data, Action $action) use ($gym): void {
                $subscription = GymSubscription::of($gym);

                if ($subscription === null) {
                    return;
                }

                try {
                    $subscription->change(self::termsFrom($data));
                } catch (BillingRefused $refused) {
                    Notification::make()->danger()->title($refused->getMessage())->send();
                    $action->halt();
                }

                Notification::make()->success()->title('Billing changed. It applies from the next invoice.')->send();
            });
    }

    public static function extendTrialAction(Organization $gym): Action
    {
        return Action::make('extendTrial')
            ->label('Extend trial')
            ->icon(Heroicon::OutlinedGift)
            ->color('gray')
            ->visible(fn (): bool => GymSubscription::of($gym)?->isOnTrial($gym->today()) === true)
            ->modalIcon(Heroicon::OutlinedGift)
            ->modalHeading('Extend the free trial')
            ->modalDescription(fn (): string => 'It ends on '.GymSubscription::of($gym)?->trial_ends_on?->format('j M Y').' now. Billing starts the day after the new end.')
            ->schema([
                DatePicker::make('until')
                    ->label('Trial ends on')
                    ->native(false)
                    ->required()
                    ->minDate(fn (): ?string => GymSubscription::of($gym)?->trial_ends_on?->addDay()->toDateString()),
            ])
            ->action(function (array $data, Action $action) use ($gym): void {
                try {
                    GymSubscription::of($gym)?->extendTrial($gym, CarbonImmutable::parse((string) $data['until']));
                } catch (BillingRefused $refused) {
                    Notification::make()->danger()->title($refused->getMessage())->send();
                    $action->halt();
                }

                Notification::make()->success()->title('Trial extended.')->send();
            });
    }

    /**
     * The billing section of the gym's page.
     */
    public static function section(): Section
    {
        return Section::make('Billing')
            ->description(fn (Organization $record): string => GymSubscription::of($record) === null
                ? 'Not billed. Set up billing to start sending invoices.'
                : 'Prices include VAT. Changes apply from the next invoice.')
            ->icon(Heroicon::OutlinedBanknotes)
            ->columns(['default' => 1, 'md' => 3])
            ->schema([
                TextEntry::make('bill_status')
                    ->label('Status')
                    ->state(fn (Organization $record): string => app(GymBills::class)->statusOf($record)->label())
                    ->badge()
                    ->color(fn (Organization $record): string => self::STATUS_COLORS[app(GymBills::class)->statusOf($record)->value]),
                TextEntry::make('bill_plan')
                    ->label('Plan')
                    ->state(fn (Organization $record): ?string => self::planLine(GymSubscription::of($record)))
                    ->placeholder('—'),
                TextEntry::make('bill_next_invoice')
                    ->label('Next invoice')
                    ->state(fn (Organization $record): ?string => self::nextInvoiceLine(GymSubscription::of($record)))
                    ->placeholder('—'),
                TextEntry::make('bill_trial')
                    ->label('Trial ends')
                    ->state(fn (Organization $record): ?string => GymSubscription::of($record)?->trial_ends_on?->format('j M Y'))
                    ->placeholder('No trial'),
                TextEntry::make('bill_grace')
                    ->label('Pauses after')
                    ->state(fn (Organization $record): ?string => ($subscription = GymSubscription::of($record)) === null ? null : $subscription->grace_days.' days unpaid')
                    ->placeholder('—'),
                TextEntry::make('bill_tin')
                    ->label('TIN')
                    ->state(fn (Organization $record): ?string => GymSubscription::of($record)?->tin)
                    ->placeholder('Not given'),
            ]);
    }

    /**
     * The plan and terms fields, shared by setting up and changing.
     *
     * @return list<mixed>
     */
    public static function termsFields(Organization $gym, ?GymSubscription $current): array
    {
        return [
            Select::make('plan')
                ->label('Plan')
                ->options(fn (): array => self::planOptions($current))
                ->native(false)
                ->required()
                ->live(),
            Select::make('months_per_invoice')
                ->label('Each invoice covers')
                ->options(fn (Get $get): array => self::monthOptions($get))
                ->default(1)
                ->native(false)
                ->required()
                ->live(),
            TextInput::make('monthly_price_override')
                ->label('Negotiated price, a month, first branch')
                ->prefix('ETB')
                ->placeholder(fn (Get $get): string => self::plan($get)?->monthly_price->toDecimal() ?? '')
                ->helperText('Leave empty to use the plan\'s price.')
                ->live(onBlur: true)
                ->rules([new ValidMoneyAmount]),
            TextInput::make('extra_branch_price_override')
                ->label('Negotiated price, a month, each extra branch')
                ->prefix('ETB')
                ->placeholder(fn (Get $get): string => self::plan($get)?->extra_branch_price->toDecimal() ?? '')
                ->helperText('Leave empty to use the plan\'s price.')
                ->live(onBlur: true)
                ->rules([new ValidMoneyAmount]),
            TextInput::make('grace_days')
                ->label('Pauses after')
                ->integer()
                ->minValue(0)
                ->maxValue(SubscriptionTerms::MOST_GRACE_DAYS)
                ->default(SubscriptionTerms::DEFAULT_GRACE_DAYS)
                ->suffix('days unpaid')
                ->helperText('Days after the due day before an unpaid invoice pauses the gym.')
                ->required(),
            TextInput::make('tin')
                ->label('Gym\'s TIN')
                ->placeholder('0012345678')
                ->regex('/^\d{10}$/')
                ->validationMessages(['regex' => 'A TIN is 10 digits.'])
                ->helperText('Optional. Shown on its invoices.'),
            TextEntry::make('bill_preview')
                ->label('Its next invoice')
                ->icon(Heroicon::OutlinedCalculator)
                ->color('gray')
                ->state(fn (Get $get): string => self::previewOf($gym, $get)),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function termsFrom(array $data): SubscriptionTerms
    {
        return new SubscriptionTerms(
            plan: PlatformPlan::query()->findOrFail((int) $data['plan']),
            monthlyPriceOverride: filled($data['monthly_price_override'] ?? null) ? Money::of((string) $data['monthly_price_override']) : null,
            extraBranchPriceOverride: filled($data['extra_branch_price_override'] ?? null) ? Money::of((string) $data['extra_branch_price_override']) : null,
            monthsPerInvoice: (int) $data['months_per_invoice'],
            graceDays: (int) ($data['grace_days'] ?? SubscriptionTerms::DEFAULT_GRACE_DAYS),
            tin: filled($data['tin'] ?? null) ? (string) $data['tin'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function formStateOf(?GymSubscription $subscription): array
    {
        if ($subscription === null) {
            return [];
        }

        return [
            'plan' => $subscription->platform_plan_id,
            'months_per_invoice' => $subscription->months_per_invoice,
            'monthly_price_override' => $subscription->monthly_price_override_minor === null ? null : Money::ofMinor($subscription->monthly_price_override_minor, $subscription->currency)->toDecimal(),
            'extra_branch_price_override' => $subscription->extra_branch_price_override_minor === null ? null : Money::ofMinor($subscription->extra_branch_price_override_minor, $subscription->currency)->toDecimal(),
            'grace_days' => $subscription->grace_days,
            'tin' => $subscription->tin,
        ];
    }

    /**
     * Offered plans, and the gym's own plan even if it has been retired.
     *
     * @return array<int, string>
     */
    private static function planOptions(?GymSubscription $current): array
    {
        return PlatformPlan::query()
            ->where(function ($query) use ($current): void {
                $query->whereNull('retired_at');

                if ($current !== null) {
                    $query->orWhereKey($current->platform_plan_id);
                }
            })
            ->orderBy('monthly_price_minor')
            ->get()
            ->mapWithKeys(fn (PlatformPlan $plan): array => [(int) $plan->getKey() => $plan->name.' · '.$plan->monthly_price->format().' a month'.($plan->isRetired() ? ' (retired)' : '')])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private static function monthOptions(Get $get): array
    {
        $plan = self::plan($get);
        $options = [];

        foreach (GymCharge::MONTHS as $months) {
            $discount = $plan?->discountFor($months) ?? 0;
            $options[$months] = ($months === 1 ? '1 month' : $months.' months').($discount > 0 ? ' ('.$discount.'% off)' : '');
        }

        return $options;
    }

    private static function plan(Get $get): ?PlatformPlan
    {
        return is_numeric($get('plan')) ? PlatformPlan::query()->find((int) $get('plan')) : null;
    }

    /**
     * "3,000.00 ETB for 2 branches, 1 month (VAT inside: 391.30 ETB)", from the form.
     */
    private static function previewOf(Organization $gym, Get $get): string
    {
        $plan = self::plan($get);

        if ($plan === null || ! in_array((int) $get('months_per_invoice'), GymCharge::MONTHS, true)) {
            return 'Choose a plan to see what the gym pays.';
        }

        try {
            $monthly = filled($get('monthly_price_override')) ? Money::of((string) $get('monthly_price_override')) : $plan->monthly_price;
            $extra = filled($get('extra_branch_price_override')) ? Money::of((string) $get('extra_branch_price_override')) : $plan->extra_branch_price;
        } catch (InvalidMoneyAmount) {
            return 'Fill in the prices as amounts, such as 1,800.';
        }

        $months = (int) $get('months_per_invoice');
        $branches = max(1, Branch::query()->acrossOrganizations()->where('organization_id', $gym->getKey())->count());
        $charge = new GymCharge($monthly, $extra, $branches, $months, $plan->discountFor($months));

        return self::chargeLine($charge);
    }

    public static function chargeLine(GymCharge $charge): string
    {
        return sprintf(
            '%s for %d %s, %d %s (VAT inside: %s)',
            $charge->total->format(),
            $charge->branches,
            $charge->branches === 1 ? 'branch' : 'branches',
            $charge->months,
            $charge->months === 1 ? 'month' : 'months',
            $charge->vat->format(),
        );
    }

    private static function planLine(?GymSubscription $subscription): ?string
    {
        if ($subscription === null) {
            return null;
        }

        return $subscription->plan->name
            .' · '.$subscription->monthlyPrice()->format().' a month'
            .($subscription->extraBranchPrice()->isZero() ? '' : ', '.$subscription->extraBranchPrice()->format().' each extra branch')
            .($subscription->hasNegotiatedPrices() ? ' (negotiated)' : '');
    }

    private static function nextInvoiceLine(?GymSubscription $subscription): ?string
    {
        if ($subscription === null) {
            return null;
        }

        return 'For the period from '.$subscription->next_period_starts_on->format('j M Y').': '.self::chargeLine($subscription->chargeNow());
    }

    /**
     * Statuses for the gym list's filter.
     *
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        $options = [];

        foreach (GymBillStatus::cases() as $status) {
            $options[$status->value] = $status->label();
        }

        return $options;
    }
}
