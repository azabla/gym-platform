<?php

declare(strict_types=1);

namespace App\Domains\Billing\Balances;

use App\Domains\Memberships\Models\Membership;
use App\Domains\Shared\Money\Money;

/**
 * Where a membership stands with its money: how much has been paid towards it,
 * after refunds, and how much is still due. A cancelled membership owes
 * nothing more. Worked out from the payments, never stored. See ADR 0008.
 */
final readonly class MembershipBalance
{
    private function __construct(
        public Money $amountPaid,
        public Money $balanceDue,
    ) {}

    /**
     * The balance of the membership, given what has been paid towards it after refunds.
     */
    public static function of(Membership $membership, Money $amountPaid): self
    {
        $balanceDue = $membership->cancelled_at === null
            ? $membership->price->subtract($amountPaid)
            : Money::zero($membership->price->currency);

        return new self($amountPaid, $balanceDue);
    }
}
