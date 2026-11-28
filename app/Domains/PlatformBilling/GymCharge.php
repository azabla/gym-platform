<?php

declare(strict_types=1);

namespace App\Domains\PlatformBilling;

use App\Domains\Shared\Money\Money;
use InvalidArgumentException;

/**
 * What a gym is charged for one invoice (ADR 0045): the first branch at the
 * monthly price, each extra branch at its own price, times the months the
 * invoice covers, less the discount for paying that many months at once.
 * Prices include VAT, so the VAT is the part of the total it already holds.
 */
final readonly class GymCharge
{
    /** Ethiopia's VAT, in percent. */
    public const VAT_PERCENT = 15;

    /** The lengths an invoice may cover, in months. */
    public const MONTHS = [1, 3, 6, 12];

    public Money $perMonth;

    public Money $beforeDiscount;

    public Money $discount;

    public Money $total;

    public Money $vat;

    public function __construct(
        public Money $monthlyPrice,
        public Money $extraBranchPrice,
        public int $branches,
        public int $months,
        public int $discountPercent,
    ) {
        if ($branches < 1) {
            throw new InvalidArgumentException('A gym is charged for at least one branch.');
        }

        if (! in_array($months, self::MONTHS, true)) {
            throw new InvalidArgumentException('An invoice covers 1, 3, 6 or 12 months.');
        }

        if ($discountPercent < 0 || $discountPercent > 50) {
            throw new InvalidArgumentException('A discount is from 0 to 50 percent.');
        }

        $this->perMonth = $monthlyPrice->add($extraBranchPrice->multiply($branches - 1));
        $this->beforeDiscount = $this->perMonth->multiply($months);
        $this->discount = $this->beforeDiscount->percentage($discountPercent * 100);
        $this->total = $this->beforeDiscount->subtract($this->discount);
        $this->vat = Money::ofMinor(
            intdiv($this->total->minor * self::VAT_PERCENT * 2 + (100 + self::VAT_PERCENT), 2 * (100 + self::VAT_PERCENT)),
            $this->total->currency,
        );
    }

    /**
     * The extra branches: every branch after the first.
     */
    public function extraBranches(): int
    {
        return $this->branches - 1;
    }
}
