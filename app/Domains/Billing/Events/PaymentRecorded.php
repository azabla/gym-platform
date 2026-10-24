<?php

declare(strict_types=1);

namespace App\Domains\Billing\Events;

use App\Domains\Billing\Models\Payment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A payment or refund was recorded, at the desk or through Chapa. It is
 * dispatched once the transaction commits, so nothing hears of a payment
 * that was rolled back.
 */
final class PaymentRecorded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Payment $payment) {}
}
