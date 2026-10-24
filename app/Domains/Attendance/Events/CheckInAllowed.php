<?php

declare(strict_types=1);

namespace App\Domains\Attendance\Events;

use App\Domains\Attendance\Models\CheckIn;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A member was let in at the door, so a visit pack may be running low.
 */
final class CheckInAllowed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly CheckIn $checkIn) {}
}
