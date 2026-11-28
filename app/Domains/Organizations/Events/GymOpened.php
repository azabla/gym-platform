<?php

declare(strict_types=1);

namespace App\Domains\Organizations\Events;

use App\Domains\Organizations\Models\Organization;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A gym was opened, with its first branch and owner (ADR 0042), once the
 * transaction commits, for what other domains start it with, such as its
 * expense categories (ADR 0047).
 */
final class GymOpened implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Organization $gym) {}
}
