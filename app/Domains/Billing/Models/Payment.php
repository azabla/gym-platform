<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Casts\AsMoney;
use App\Domains\Billing\Balances\MembershipBalance;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Enums\PaymentType;
use App\Domains\Billing\Events\PaymentRecorded;
use App\Domains\Billing\Exceptions\PaymentRefused;
use App\Domains\Billing\Policies\PaymentPolicy;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\BelongsToOrganization;
use App\Domains\Shared\Money\Currency;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Money a member paid towards a membership, or money given back to them as a
 * refund. The amount is always positive; the type says which way it went.
 * Every payment has its gym's next receipt number, and one recorded by mistake
 * is voided, never deleted. See ADR 0008.
 *
 * @property Money $amount The amount (amount_minor and currency).
 */
#[UseFactory(PaymentFactory::class)]
#[UsePolicy(PaymentPolicy::class)]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use BelongsToOrganization, HasFactory;

    /**
     * Record money the member paid towards the membership: by the given method,
     * at the branch, on the gym's date it was paid, received by the staff
     * member (nobody, for an online payment). The membership is locked first,
     * so two desks can't both take its last payment. See refusalToReceive().
     */
    public static function receive(Membership $membership, Money $amount, PaymentMethod $method, ?string $reference, Branch $branch, CarbonImmutable $paidOn, ?Staff $receivedBy): self
    {
        return DB::transaction(function () use ($membership, $amount, $method, $reference, $branch, $paidOn, $receivedBy): self {
            $refusal = static::refusalToReceive(self::lock($membership), $amount);

            if ($refusal !== null) {
                throw $refusal;
            }

            return self::record(PaymentType::Payment, $membership, $amount, $method, $reference, $branch, $paidOn, $receivedBy);
        });
    }

    /**
     * Give money back to the member for the cancelled membership: by the given
     * method, at the branch, on the gym's date it was given. The membership is
     * locked first, like receive(). See refusalToRefund().
     */
    public static function refund(Membership $membership, Money $amount, PaymentMethod $method, ?string $reference, Branch $branch, CarbonImmutable $paidOn, Staff $refundedBy): self
    {
        return DB::transaction(function () use ($membership, $amount, $method, $reference, $branch, $paidOn, $refundedBy): self {
            $refusal = static::refusalToRefund(self::lock($membership), $amount);

            if ($refusal !== null) {
                throw $refusal;
            }

            return self::record(PaymentType::Refund, $membership, $amount, $method, $reference, $branch, $paidOn, $refundedBy);
        });
    }

    /**
     * Why the member can't pay this amount towards the membership, or null when
     * they can: a cancelled membership owes nothing more, and a payment can't
     * be more than the balance due.
     */
    public static function refusalToReceive(Membership $membership, Money $amount): ?PaymentRefused
    {
        if ($membership->cancelled_at !== null) {
            return PaymentRefused::membershipCancelled();
        }

        $balanceDue = static::balanceOf($membership)->balanceDue;

        return $amount->isGreaterThan($balanceDue) ? PaymentRefused::moreThanBalanceDue($balanceDue) : null;
    }

    /**
     * Why this amount can't be refunded for the membership, or null when it can:
     * only a cancelled membership is refunded, and never for more than was paid
     * towards it.
     */
    public static function refusalToRefund(Membership $membership, Money $amount): ?PaymentRefused
    {
        if ($membership->cancelled_at === null) {
            return PaymentRefused::membershipNotCancelled();
        }

        $amountPaid = static::balanceOf($membership)->amountPaid;

        return $amount->isGreaterThan($amountPaid) ? PaymentRefused::moreThanAmountPaid($amountPaid) : null;
    }

    /**
     * Where the membership stands with its money. See MembershipBalance.
     */
    public static function balanceOf(Membership $membership): MembershipBalance
    {
        return static::balancesOf([$membership])[$membership->id];
    }

    /**
     * Where each of the memberships stands with its money: one query for all of
     * them, keyed by membership id. Refunds count against what was paid, and
     * voided payments don't count at all.
     *
     * @param  array<int, Membership>  $memberships
     * @return array<int, MembershipBalance>
     */
    public static function balancesOf(array $memberships): array
    {
        if ($memberships === []) {
            return [];
        }

        $amountsPaidMinor = static::query()
            ->whereIn('membership_id', array_map(fn (Membership $membership): int => $membership->id, $memberships))
            ->counted()
            ->groupBy('membership_id')
            ->selectRaw('membership_id, SUM(CASE WHEN type = ? THEN -amount_minor ELSE amount_minor END) AS amount_paid_minor', [PaymentType::Refund->value])
            ->pluck('amount_paid_minor', 'membership_id');

        $balances = [];

        foreach ($memberships as $membership) {
            $balances[$membership->id] = MembershipBalance::of(
                $membership,
                Money::ofMinor((int) ($amountsPaidMinor[$membership->id] ?? 0), $membership->price->currency),
            );
        }

        return $balances;
    }

    /**
     * How much each of the members still owes across their memberships, keyed
     * by member id. Members without a membership that counts are left out.
     *
     * @param  array<int, int>  $memberIds
     * @return array<int, Money>
     */
    public static function balancesDueOf(array $memberIds): array
    {
        $memberships = Membership::query()->whereIn('member_id', $memberIds)->notCancelled()->get();
        $balances = static::balancesOf($memberships->all());
        $balancesDue = [];

        foreach ($memberships as $membership) {
            $balanceDue = $balances[$membership->id]->balanceDue;

            $balancesDue[$membership->member_id] = isset($balancesDue[$membership->member_id])
                ? $balancesDue[$membership->member_id]->add($balanceDue)
                : $balanceDue;
        }

        return $balancesDue;
    }

    /**
     * What the payments the query finds add up to, leaving out voided ones:
     * the total received, the total refunded, the net, and the net for each
     * method. Every method is listed, even with nothing taken.
     *
     * @param  Builder<self>  $payments
     * @return array{received: Money, refunded: Money, net: Money, by_method: array<string, Money>}
     */
    public static function totalsOf(Builder $payments): array
    {
        $sums = (clone $payments)
            ->counted()
            ->groupBy('method', 'type', 'currency')
            ->selectRaw('method, type, currency, SUM(amount_minor) AS total_minor')
            ->toBase()
            ->get();

        $received = Money::zero();
        $refunded = Money::zero();
        $netByMethod = [];

        foreach (PaymentMethod::cases() as $method) {
            $netByMethod[$method->value] = Money::zero();
        }

        foreach ($sums as $sum) {
            $total = Money::ofMinor((int) $sum->total_minor, Currency::from($sum->currency));

            if ($sum->type === PaymentType::Refund->value) {
                $refunded = $refunded->add($total);
                $netByMethod[$sum->method] = $netByMethod[$sum->method]->subtract($total);
            } else {
                $received = $received->add($total);
                $netByMethod[$sum->method] = $netByMethod[$sum->method]->add($total);
            }
        }

        return [
            'received' => $received,
            'refunded' => $refunded,
            'net' => $received->subtract($refunded),
            'by_method' => $netByMethod,
        ];
    }

    /**
     * The gym's next receipt number. The upsert locks the gym's counter until
     * the transaction ends, so two payments never get the same number, and a
     * payment that fails rolls its number back, so none is skipped. Call it
     * inside the transaction that saves the payment.
     */
    public static function nextReceiptNumber(int $organizationId): int
    {
        return (int) DB::scalar(
            'INSERT INTO receipt_counters (organization_id, last_number) VALUES (?, 1)
            ON CONFLICT (organization_id) DO UPDATE SET last_number = receipt_counters.last_number + 1
            RETURNING last_number',
            [$organizationId],
            useReadPdo: false,
        );
    }

    /**
     * A transaction number as it is stored: without spaces and in capitals, so
     * the same number can't be recorded twice by typing it differently.
     */
    public static function tidyReference(string $reference): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $reference));
    }

    /**
     * The payment that counts and already has this transaction number for the
     * method, if any: the same transfer can't be recorded twice.
     */
    public static function recordedWithReference(PaymentMethod $method, string $reference): ?self
    {
        return static::query()->counted()->where('method', $method)->where('reference', static::tidyReference($reference))->first();
    }

    /**
     * Void the payment or refund, because it was recorded by mistake. It stays
     * on record, with who voided it and why, but no longer counts. Its
     * membership is locked first, like receive(). See refusalToVoid().
     */
    public function void(Staff $voidedBy, string $reason): void
    {
        DB::transaction(function () use ($voidedBy, $reason): void {
            self::lock($this->membership);
            $refusal = $this->refresh()->refusalToVoid();

            if ($refusal !== null) {
                throw $refusal;
            }

            $this->voided_at = CarbonImmutable::now();
            $this->voidedBy()->associate($voidedBy);
            $this->void_reason = $reason;
            $this->save();
        });
    }

    /**
     * Why the payment can't be voided, or null when it can: it mustn't be
     * voided already, and voiding a payment mustn't leave more refunded than
     * paid towards its membership. Void the refund first.
     */
    public function refusalToVoid(): ?PaymentRefused
    {
        if ($this->voided_at !== null) {
            return PaymentRefused::alreadyVoided();
        }

        if ($this->type === PaymentType::Payment && $this->amount->isGreaterThan(static::balanceOf($this->membership)->amountPaid)) {
            return PaymentRefused::voidLeavesMoreRefundedThanPaid();
        }

        return null;
    }

    /** @return BelongsTo<Membership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    /**
     * The branch where the money was taken.
     *
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Staff, $this> */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'received_by_staff_id');
    }

    /** @return BelongsTo<Staff, $this> */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'voided_by_staff_id');
    }

    /**
     * Payments that count: not voided.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function counted(Builder $query): void
    {
        $query->whereNull('voided_at');
    }

    /**
     * The membership as it is now, locked until the transaction ends.
     */
    private static function lock(Membership $membership): Membership
    {
        return Membership::query()->whereKey($membership->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Save the payment or refund with the gym's next receipt number. A typed
     * transaction number is tidied; an online provider's reference is kept
     * exactly as it was given.
     */
    private static function record(PaymentType $type, Membership $membership, Money $amount, PaymentMethod $method, ?string $reference, Branch $branch, CarbonImmutable $paidOn, ?Staff $receivedBy): self
    {
        $payment = new self;
        $payment->organization()->associate($membership->organization_id);
        $payment->receipt_number = static::nextReceiptNumber($membership->organization_id);
        $payment->type = $type;
        $payment->membership()->associate($membership);
        $payment->amount = $amount;
        $payment->method = $method;
        $payment->reference = $reference === null || $method->isOnline() ? $reference : static::tidyReference($reference);
        $payment->paid_on = $paidOn;
        $payment->branch()->associate($branch);
        $payment->receivedBy()->associate($receivedBy);
        $payment->save();

        PaymentRecorded::dispatch($payment);

        return $payment;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'receipt_number' => 'integer',
            'type' => PaymentType::class,
            'amount' => AsMoney::class.':amount_minor,currency',
            'method' => PaymentMethod::class,
            'paid_on' => 'immutable_date',
            'voided_at' => 'immutable_datetime',
        ];
    }
}
