<?php

namespace App\Notifications;

use App\Domains\Members\Models\Member;
use App\Domains\Organizations\Models\BranchClosure;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/**
 * Tells the members of a closed branch (by home branch) about its closure:
 * when it is added and starts soon, and again the day before (ADR 0037 and
 * ADR 0039). Keeps when they were told, so a reminder isn't sent twice and
 * taking the closure back tells only those who knew. Run inside the gym.
 */
final class BranchClosureMessages
{
    public static function tellMembersOf(BranchClosure $closure): void
    {
        Notification::send(
            Member::query()->homeAtAnyOf([(int) $closure->branch_id])->get(),
            BranchClosing::for($closure),
        );

        $closure->forceFill(['members_told_at' => CarbonImmutable::now()])->save();
    }
}
