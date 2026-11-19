<?php

namespace App\Notifications;

use App\Domains\Memberships\Models\Membership;
use App\Domains\Notifications\Enums\StaffNotificationTopic;

/**
 * A member ended a freeze early themselves, in the app, because they are back
 * sooner (ADR 0026 and ADR 0027). It opens the membership.
 */
final class MemberEndedFreeze extends StaffNotification
{
    public static function for(Membership $membership): self
    {
        return new self(
            details: [
                'member' => (string) $membership->member?->full_name,
                'plan' => (string) $membership->plan?->name,
                'membership_ends_on' => $membership->ends_on->toDateString(),
            ],
            data: [
                'membership_id' => (string) $membership->id,
            ],
        );
    }

    public function databaseType(object $notifiable): string
    {
        return 'staff_member_ended_freeze';
    }

    public function topic(): StaffNotificationTopic
    {
        return StaffNotificationTopic::AppFreezes;
    }
}
