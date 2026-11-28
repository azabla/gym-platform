<?php

declare(strict_types=1);

namespace App\Domains\PlatformBilling\Events;

use App\Domains\PlatformBilling\Enums\GymBillReminder;
use App\Domains\PlatformBilling\Models\GymInvoice;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A gym's owners are to be reminded of an unpaid invoice (ADR 0045), once
 * the transaction commits.
 */
final class GymBillReminderDue implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly GymInvoice $invoice,
        public readonly GymBillReminder $reminder,
    ) {}
}
