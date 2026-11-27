<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domains\Members\Models\Member;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;

/**
 * A paused gym sends nothing (ADR 0043): a message queued before the pause,
 * to one of its members or staff, is dropped when its turn comes. Laravel
 * asks shouldSend() for each channel just before sending.
 */
trait HeldWhileTheGymIsPaused
{
    public function shouldSend(object $notifiable, string $channel): bool
    {
        $gym = $notifiable instanceof Member || $notifiable instanceof Staff
            ? Organization::query()->find($notifiable->organization_id)
            : null;

        return ! ($gym instanceof Organization && $gym->isSuspended());
    }
}
