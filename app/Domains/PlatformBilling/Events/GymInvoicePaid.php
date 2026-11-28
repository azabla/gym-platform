<?php

declare(strict_types=1);

namespace App\Domains\PlatformBilling\Events;

use App\Domains\PlatformBilling\Models\GymInvoice;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A gym finished paying one of the platform's invoices (ADR 0045), once the
 * transaction commits.
 */
final class GymInvoicePaid implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly GymInvoice $invoice,
        public readonly bool $reopenedTheGym = false,
    ) {}
}
