<?php

declare(strict_types=1);

namespace App\Domains\Organizations\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Staff closed one or more branches on some days, or gave them other hours
 * (ADR 0037). Members of those branches hear of it when it is soon.
 */
final class BranchClosureScheduled implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<int>  $closureIds  one closure for each branch
     */
    public function __construct(
        public readonly int $organizationId,
        public readonly array $closureIds,
    ) {}
}
