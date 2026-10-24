<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Casts\AsMoney;
use App\Domains\Billing\Chapa\ChapaClient;
use App\Domains\Billing\Chapa\ChapaTransaction;
use App\Domains\Billing\Chapa\Exceptions\ChapaRequestFailed;
use App\Domains\Billing\Enums\PaymentLinkStatus;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Exceptions\PaymentRefused;
use App\Domains\Billing\Policies\PaymentLinkPolicy;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\BelongsToOrganization;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Database\Factories\PaymentLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A link to Chapa's payment page for an amount owed on a membership. Staff
 * share it with the member, who pays with any method the gym's own Chapa
 * account accepts; once Chapa confirms the payment, it is recorded like any
 * other. A membership has at most one pending link. See ADR 0009.
 *
 * @property Money $amount The amount asked for (amount_minor and currency).
 */
#[UseFactory(PaymentLinkFactory::class)]
#[UsePolicy(PaymentLinkPolicy::class)]
class PaymentLink extends Model
{
    /** @use HasFactory<PaymentLinkFactory> */
    use BelongsToOrganization, HasFactory;

    /**
     * Ask Chapa, with the gym's own account, for a payment page for the amount
     * owed on the membership, and keep the link to it. Staff create links, and
     * so do members in their app (no staff member then). Chapa calls the
     * callback URL once the member has paid. Nothing is saved when Chapa
     * refuses or doesn't answer.
     */
    public static function open(Membership $membership, Money $amount, Branch $branch, ?Staff $createdBy, ChapaAccount $chapaAccount, string $callbackUrl): self
    {
        $link = new self;
        $link->organization()->associate($membership->organization_id);
        $link->membership()->associate($membership);
        $link->branch()->associate($branch);
        $link->createdBy()->associate($createdBy);
        $link->amount = $amount;
        $link->tx_ref = 'GP-'.Str::ulid();
        $link->status = PaymentLinkStatus::Pending;
        $link->checkout_url = app(ChapaClient::class)->startCheckout($link, $chapaAccount->secret_key, $callbackUrl);
        $link->save();

        return $link;
    }

    /**
     * Cancel the open link, for example because the member paid another way.
     * If the member still pays on Chapa's page, the payment is recorded when
     * the membership still owes it (see settle()).
     */
    public function cancel(Staff $cancelledBy): void
    {
        $this->status = PaymentLinkStatus::Cancelled;
        $this->cancelled_at = CarbonImmutable::now();
        $this->cancelledBy()->associate($cancelledBy);
        $this->save();
    }

    /**
     * Ask Chapa, with the gym's own key, whether the member has paid, and
     * record the payment if so. A link that is already settled isn't asked
     * about again.
     *
     * @throws ChapaRequestFailed
     */
    public function check(ChapaAccount $chapaAccount): void
    {
        if ($this->settled_at !== null) {
            return;
        }

        $this->settle(app(ChapaClient::class)->verify($chapaAccount->secret_key, $this->tx_ref));
    }

    /**
     * Record the member's payment, as Chapa reports it. Nothing happens unless
     * Chapa says it succeeded for exactly what the link asked for, and a link
     * is settled only once, so Chapa telling us twice records one payment.
     * When the membership no longer owes the amount (it was paid another way,
     * or cancelled), Chapa has taken money that is not due: the link is marked
     * refund needed, for the gym to refund from its Chapa dashboard.
     */
    public function settle(ChapaTransaction $transaction): void
    {
        DB::transaction(function () use ($transaction): void {
            $paymentLink = static::query()->whereKey($this->id)->lockForUpdate()->firstOrFail();

            if ($paymentLink->settled_at !== null || ! $transaction->pays($paymentLink)) {
                return;
            }

            $paymentLink->chapa_reference = $transaction->reference;
            $paymentLink->settled_at = CarbonImmutable::now();

            try {
                $payment = Payment::receive(
                    membership: $paymentLink->membership,
                    amount: $paymentLink->amount,
                    method: PaymentMethod::Chapa,
                    reference: $transaction->reference,
                    branch: $paymentLink->branch,
                    paidOn: $paymentLink->organization->today(),
                    receivedBy: null,
                );
            } catch (PaymentRefused) {
                $paymentLink->status = PaymentLinkStatus::RefundNeeded;
                $paymentLink->save();

                return;
            }

            $paymentLink->status = PaymentLinkStatus::Paid;
            $paymentLink->payment()->associate($payment);
            $paymentLink->save();
        });

        $this->refresh();
    }

    /** @return BelongsTo<Membership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    /**
     * The branch the payment is for.
     *
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Staff, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'created_by_staff_id');
    }

    /** @return BelongsTo<Staff, $this> */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'cancelled_by_staff_id');
    }

    /**
     * The payment the link became, once Chapa confirmed it.
     *
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * Links still waiting for the member to pay.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->where('status', PaymentLinkStatus::Pending);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => AsMoney::class.':amount_minor,currency',
            'status' => PaymentLinkStatus::class,
            'settled_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }
}
