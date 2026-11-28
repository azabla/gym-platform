<?php

declare(strict_types=1);

namespace App\Domains\PlatformBilling;

use App\Domains\PlatformBilling\Models\PlatformPlan;
use App\Domains\Shared\Money\Money;
use InvalidArgumentException;

/**
 * The terms a gym is billed on (ADR 0045): its plan, any negotiated prices
 * in place of the plan's, how many months each invoice covers, how many days
 * after the due day an unpaid invoice pauses the gym, and the gym's TIN.
 */
final readonly class SubscriptionTerms
{
    /** The most days of grace after the due day. */
    public const MOST_GRACE_DAYS = 60;

    public const DEFAULT_GRACE_DAYS = 14;

    public function __construct(
        public PlatformPlan $plan,
        public ?Money $monthlyPriceOverride = null,
        public ?Money $extraBranchPriceOverride = null,
        public int $monthsPerInvoice = 1,
        public int $graceDays = self::DEFAULT_GRACE_DAYS,
        public ?string $tin = null,
    ) {
        if (! in_array($monthsPerInvoice, GymCharge::MONTHS, true)) {
            throw new InvalidArgumentException('An invoice covers 1, 3, 6 or 12 months.');
        }

        if ($graceDays < 0 || $graceDays > self::MOST_GRACE_DAYS) {
            throw new InvalidArgumentException('Grace is from 0 to '.self::MOST_GRACE_DAYS.' days.');
        }

        foreach ([$monthlyPriceOverride, $extraBranchPriceOverride] as $price) {
            if ($price !== null && $price->isNegative()) {
                throw new InvalidArgumentException('A price can\'t be negative.');
            }
        }
    }
}
