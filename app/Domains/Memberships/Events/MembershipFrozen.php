<?php

declare(strict_types=1);

namespace App\Domains\Memberships\Events;

use App\Domains\Memberships\Models\MembershipFreeze;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Staff froze a membership. Dispatched once the transaction commits.
 */
final class MembershipFrozen implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly MembershipFreeze $freeze) {}
}
