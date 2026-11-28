<?php

declare(strict_types=1);

namespace App\Domains\PlatformBilling\Events;

use App\Domains\PlatformBilling\Models\GymInvoice;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The platform issued an invoice to a gym (ADR 0045), once the transaction commits.
 */
final class GymInvoiceIssued implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly GymInvoice $invoice) {}
}
