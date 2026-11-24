<?php

declare(strict_types=1);

namespace App\Domains\Organizations\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Staff took back a closure the branch's members had been told about, before
 * it ended, so the branch keeps its usual hours those days (ADR 0039). The
 * closure is gone by the time this is heard, so its days come with it.
 */
final class BranchClosureTakenBack implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $organizationId,
        public readonly int $branchId,
        public readonly string $startsOn,
        public readonly string $endsOn,
    ) {}
}
