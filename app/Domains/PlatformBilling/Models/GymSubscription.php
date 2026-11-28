<?php

declare(strict_types=1);

namespace App\Domains\PlatformBilling\Models;

use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Tenancy\BelongsToOrganization;
use App\Domains\PlatformBilling\Exceptions\BillingRefused;
use App\Domains\PlatformBilling\GymCharge;
use App\Domains\PlatformBilling\SubscriptionTerms;
use App\Domains\Shared\Money\Currency;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Database\Factories\GymSubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How the platform bills one gym (ADR 0045): its plan and any negotiated
 * prices, how many months each invoice covers, its free trial, the first day
 * of the next period to invoice, and its grace after the due day. A gym
 * without one isn't billed. Changes apply from the next invoice; invoices
 * already issued keep what they were issued with.
 *
 * @property int $platform_plan_id
 * @property int|null $monthly_price_override_minor
 * @property int|null $extra_branch_price_override_minor
 * @property Currency $currency
 * @property int $months_per_invoice
 * @property CarbonImmutable|null $trial_ends_on
 * @property CarbonImmutable $next_period_starts_on
 * @property int $grace_days
 * @property string|null $tin
 * @property-read PlatformPlan $plan
 */
#[UseFactory(GymSubscriptionFactory::class)]
class GymSubscription extends Model
{
    /** @use HasFactory<GymSubscriptionFactory> */
    use BelongsToOrganization, HasFactory;

    /** A new gym's free trial, in days, unless the admin chooses otherwise. */
    public const DEFAULT_TRIAL_DAYS = 30;

    /** The longest trial, in days. */
    public const MOST_TRIAL_DAYS = 365;

    /**
     * Start billing a gym: from the day after a free trial of so many days,
     * or, without a trial, from the first billing day given (today at the
     * earliest, where the gym is).
     *
     * @throws BillingRefused when the gym is already billed, the plan is retired or the first day has passed
     */
    public static function start(Organization $gym, SubscriptionTerms $terms, int $trialDays = 0, ?CarbonImmutable $firstBillingDay = null): self
    {
        if (self::of($gym) !== null) {
            throw BillingRefused::alreadyBilled();
        }

        if ($terms->plan->isRetired()) {
            throw BillingRefused::retiredPlan();
        }

        $today = $gym->today();
        $firstBillingDay = $trialDays > 0 ? $today->addDays($trialDays) : ($firstBillingDay ?? $today);

        if ($firstBillingDay->lessThan($today)) {
            throw BillingRefused::firstDayInThePast();
        }

        $subscription = new self;
        $subscription->forceFill([
            'organization_id' => $gym->getKey(),
            'trial_ends_on' => $trialDays > 0 ? $firstBillingDay->subDay() : null,
            'next_period_starts_on' => $firstBillingDay,
        ]);
        $subscription->applyTerms($terms)->save();

        return $subscription;
    }

    /**
     * The gym's subscription, or null when it isn't billed.
     */
    public static function of(Organization $gym): ?self
    {
        return self::query()->acrossOrganizations()->where('organization_id', $gym->getKey())->first();
    }

    /**
     * Change the plan, prices, months per invoice, grace or TIN. It applies
     * from the next invoice. A gym may stay on its plan after it is retired,
     * but can't be moved onto a retired one.
     *
     * @throws BillingRefused when moving onto a retired plan
     */
    public function change(SubscriptionTerms $terms): void
    {
        if ($terms->plan->isRetired() && $terms->plan->getKey() !== $this->platform_plan_id) {
            throw BillingRefused::retiredPlan();
        }

        $this->applyTerms($terms)->save();
    }

    /**
     * Give the gym a longer trial, to the given day; billing starts the day after.
     *
     * @throws BillingRefused when the trial has ended or the day isn't later than its end
     */
    public function extendTrial(Organization $gym, CarbonImmutable $until): void
    {
        if (! $this->isOnTrial($gym->today()) || $this->trial_ends_on === null) {
            throw BillingRefused::notOnTrial();
        }

        if (! $until->greaterThan($this->trial_ends_on)) {
            throw BillingRefused::trialNotLonger();
        }

        $firstInvoiceIssued = GymInvoice::query()->acrossOrganizations()
            ->where('organization_id', $this->organization_id)
            ->whereNull('voided_at')
            ->where('period_starts_on', '>', $this->trial_ends_on)
            ->exists();

        if ($firstInvoiceIssued) {
            throw BillingRefused::firstInvoiceIssued();
        }

        $this->forceFill(['trial_ends_on' => $until, 'next_period_starts_on' => $until->addDay()])->save();
    }

    public function isOnTrial(CarbonImmutable $today): bool
    {
        return $this->trial_ends_on !== null && $today->lessThanOrEqualTo($this->trial_ends_on);
    }

    /** @return BelongsTo<PlatformPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(PlatformPlan::class, 'platform_plan_id');
    }

    /**
     * The monthly price for the first branch: the negotiated one, or the plan's.
     */
    public function monthlyPrice(): Money
    {
        return $this->monthly_price_override_minor === null
            ? $this->plan->monthly_price
            : Money::ofMinor($this->monthly_price_override_minor, $this->currency);
    }

    /**
     * The monthly price for each extra branch: the negotiated one, or the plan's.
     */
    public function extraBranchPrice(): Money
    {
        return $this->extra_branch_price_override_minor === null
            ? $this->plan->extra_branch_price
            : Money::ofMinor($this->extra_branch_price_override_minor, $this->currency);
    }

    public function hasNegotiatedPrices(): bool
    {
        return $this->monthly_price_override_minor !== null || $this->extra_branch_price_override_minor !== null;
    }

    /**
     * What the gym's next invoice comes to with this many branches.
     */
    public function charge(int $branches): GymCharge
    {
        return new GymCharge(
            $this->monthlyPrice(),
            $this->extraBranchPrice(),
            max(1, $branches),
            $this->months_per_invoice,
            $this->plan->discountFor($this->months_per_invoice),
        );
    }

    /**
     * What the gym's next invoice comes to with the branches it has now.
     */
    public function chargeNow(): GymCharge
    {
        return $this->charge(Branch::query()->acrossOrganizations()->where('organization_id', $this->organization_id)->count());
    }

    private function applyTerms(SubscriptionTerms $terms): self
    {
        return $this->forceFill([
            'platform_plan_id' => $terms->plan->getKey(),
            'monthly_price_override_minor' => $terms->monthlyPriceOverride?->minor,
            'extra_branch_price_override_minor' => $terms->extraBranchPriceOverride?->minor,
            'currency' => $terms->plan->monthly_price->currency,
            'months_per_invoice' => $terms->monthsPerInvoice,
            'grace_days' => $terms->graceDays,
            'tin' => blank($terms->tin) ? null : trim((string) $terms->tin),
        ])->setRelation('plan', $terms->plan);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform_plan_id' => 'integer',
            'monthly_price_override_minor' => 'integer',
            'extra_branch_price_override_minor' => 'integer',
            'currency' => Currency::class,
            'months_per_invoice' => 'integer',
            'trial_ends_on' => 'immutable_date',
            'next_period_starts_on' => 'immutable_date',
            'grace_days' => 'integer',
        ];
    }
}
