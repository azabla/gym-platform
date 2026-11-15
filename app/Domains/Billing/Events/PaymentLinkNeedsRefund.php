<?php

declare(strict_types=1);

namespace App\Domains\Billing\Events;

use App\Domains\Billing\Models\PaymentLink;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Chapa took a member's money for a link when nothing was due any more, so
 * the gym must refund it from its Chapa dashboard (ADR 0009). The gym's staff
 * hear of it (ADR 0027).
 */
final class PaymentLinkNeedsRefund implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly PaymentLink $paymentLink) {}
}
