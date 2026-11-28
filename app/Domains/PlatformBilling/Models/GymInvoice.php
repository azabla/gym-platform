<?php

declare(strict_types=1);

namespace App\Domains\PlatformBilling\Models;

use App\Casts\AsMoney;
use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Tenancy\BelongsToOrganization;
use App\Domains\PlatformBilling\Events\GymInvoiceIssued;
use App\Domains\PlatformBilling\Exceptions\BillingRefused;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * One invoice from the platform to a gym (ADR 0045), for a period of 1, 3, 6
 * or 12 months. It is issued 7 days before the period starts, is due on its
 * first day, and pauses the gym if still unpaid on its pause day. It keeps
 * the plan, branches and prices it was issued with; a wrong one is voided,
 * and the latest one voided is issued again on the next run.
 *
 * @property int $number
 * @property CarbonImmutable $period_starts_on
 * @property CarbonImmutable $period_ends_on
 * @property CarbonImmutable $issued_on
 * @property CarbonImmutable $due_on
 * @property CarbonImmutable $pauses_on
 * @property string $plan_name
 * @property int $branches
 * @property int $months
 * @property Money $monthly_price
 * @property Money $extra_branch_price
 * @property int $discount_percent
 * @property Money $total
 * @property Money $vat
 * @property string|null $tin
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $voided_at
 * @property string|null $void_reason
 * @property-read User|null $voidedBy
 */
class GymInvoice extends Model
{
    use BelongsToOrganization;

    /** How many days before its period an invoice is issued. */
    public const ISSUED_DAYS_AHEAD = 7;

    /** The most invoices one run issues for a gym, catching up after missed runs. */
    private const MOST_AT_ONCE = 12;

    /**
     * Issue every invoice that has come due to be issued for the gym: each
     * period's, 7 days before it starts. A period never starts in the past:
     * if its first day went by without an invoice (the gym was paused, or no
     * run happened), it starts today. Nothing is issued while the gym is paused.
     *
     * @return list<self> the invoices issued
     */
    public static function issueDue(Organization $gym): array
    {
        $issued = [];

        while (count($issued) < self::MOST_AT_ONCE && ($invoice = self::issueNext($gym)) !== null) {
            $issued[] = $invoice;
        }

        return $issued;
    }

    /**
     * Issue the gym's next invoice if it has come due to be issued.
     */
    public static function issueNext(Organization $gym): ?self
    {
        return DB::transaction(function () use ($gym): ?self {
            $subscription = GymSubscription::query()->acrossOrganizations()
                ->where('organization_id', $gym->getKey())
                ->lockForUpdate()
                ->first();
            $today = $gym->today();

            if ($subscription === null || $gym->refresh()->isSuspended()) {
                return null;
            }

            $startsOn = $subscription->next_period_starts_on->max($today);

            if ($today->lessThan($startsOn->subDays(self::ISSUED_DAYS_AHEAD))) {
                return null;
            }

            $branches = max(1, Branch::query()->acrossOrganizations()->where('organization_id', $gym->getKey())->count());
            $charge = $subscription->charge($branches);
            $endsOn = $startsOn->addMonthsNoOverflow($subscription->months_per_invoice)->subDay();

            $invoice = new self;
            $invoice->forceFill([
                'organization_id' => $gym->getKey(),
                'number' => self::nextNumber(),
                'period_starts_on' => $startsOn,
                'period_ends_on' => $endsOn,
                'issued_on' => $today,
                'due_on' => $startsOn,
                'pauses_on' => $startsOn->addDays($subscription->grace_days),
                'plan_name' => $subscription->plan->name,
                'branches' => $branches,
                'months' => $subscription->months_per_invoice,
                'monthly_price' => $charge->monthlyPrice,
                'extra_branch_price' => $charge->extraBranchPrice,
                'discount_percent' => $charge->discountPercent,
                'total' => $charge->total,
                'vat' => $charge->vat,
                'tin' => $subscription->tin,
            ])->save();

            $subscription->forceFill(['next_period_starts_on' => $endsOn->addDay()])->save();

            GymInvoiceIssued::dispatch($invoice);

            return $invoice;
        });
    }

    /**
     * Void the invoice, saying why. When it is the gym's latest, its period
     * is issued again on the next run, with the gym's terms as they are then.
     *
     * @throws BillingRefused when it is paid or already voided
     */
    public function void(User $by, string $reason): void
    {
        if (trim($reason) === '') {
            throw BillingRefused::noVoidReason();
        }

        DB::transaction(function () use ($by, $reason): void {
            $invoice = self::query()->acrossOrganizations()->lockForUpdate()->findOrFail($this->getKey());

            if ($invoice->voided_at !== null) {
                throw BillingRefused::alreadyVoided();
            }

            if ($invoice->paid_at !== null) {
                throw BillingRefused::paidInvoice();
            }

            $this->forceFill(['voided_at' => CarbonImmutable::now(), 'voided_by_user_id' => $by->getKey(), 'void_reason' => trim($reason)])->save();

            $subscription = GymSubscription::query()->acrossOrganizations()->where('organization_id', $this->organization_id)->lockForUpdate()->first();

            if ($subscription !== null && $subscription->next_period_starts_on->equalTo($this->period_ends_on->addDay())) {
                $subscription->forceFill(['next_period_starts_on' => $this->period_starts_on])->save();
            }
        });
    }

    /**
     * "INV-000123"
     */
    public function reference(): string
    {
        return sprintf('INV-%06d', $this->number);
    }

    public function isOpen(): bool
    {
        return $this->paid_at === null && $this->voided_at === null;
    }

    public function isOverdueOn(CarbonImmutable $today): bool
    {
        return $this->isOpen() && $today->greaterThan($this->due_on);
    }

    /**
     * Invoices still to be paid: neither paid nor voided.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereNull('paid_at')->whereNull('voided_at');
    }

    /** @return BelongsTo<User, $this> */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_user_id');
    }

    /**
     * The next invoice number across the platform. Inside the issuing
     * transaction, so a rolled-back invoice gives its number back.
     */
    private static function nextNumber(): int
    {
        return (int) DB::scalar(
            "INSERT INTO platform_counters (name, last_number) VALUES ('gym_invoices', 1)
            ON CONFLICT (name) DO UPDATE SET last_number = platform_counters.last_number + 1
            RETURNING last_number",
            useReadPdo: false,
        );
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'period_starts_on' => 'immutable_date',
            'period_ends_on' => 'immutable_date',
            'issued_on' => 'immutable_date',
            'due_on' => 'immutable_date',
            'pauses_on' => 'immutable_date',
            'branches' => 'integer',
            'months' => 'integer',
            'monthly_price' => AsMoney::class.':monthly_price_minor,currency',
            'extra_branch_price' => AsMoney::class.':extra_branch_price_minor,currency',
            'discount_percent' => 'integer',
            'total' => AsMoney::class.':total_minor,currency',
            'vat' => AsMoney::class.':vat_minor,currency',
            'paid_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
        ];
    }
}
