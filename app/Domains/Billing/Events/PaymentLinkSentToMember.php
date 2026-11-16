<?php

declare(strict_types=1);

namespace App\Domains\Billing\Events;

use App\Domains\Billing\Models\PaymentLink;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Staff asked for an open payment link to be texted to the member, for
 * members without the app (ADR 0031). The member hears of it by SMS.
 */
final class PaymentLinkSentToMember implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly PaymentLink $paymentLink) {}
}
