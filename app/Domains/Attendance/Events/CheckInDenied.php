<?php

declare(strict_types=1);

namespace App\Domains\Attendance\Events;

use App\Domains\Attendance\Models\CheckIn;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A member was turned away at the door. Only for a member we know: a scan of
 * a code nobody can be matched to has no one to tell.
 */
final class CheckInDenied implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly CheckIn $checkIn) {}
}
