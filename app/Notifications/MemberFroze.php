<?php

namespace App\Notifications;

use App\Domains\Memberships\Models\MembershipFreeze;
use App\Domains\Notifications\Enums\StaffNotificationTopic;

/**
 * A member froze their membership themselves, in the app (ADR 0026 and ADR
 * 0027). It opens the membership.
 */
final class MemberFroze extends StaffNotification
{
    public static function for(MembershipFreeze $freeze): self
    {
        $membership = $freeze->membership;

        return new self(
            details: [
                'member' => (string) $membership->member?->full_name,
                'plan' => (string) $membership->plan?->name,
                'starts_on' => $freeze->starts_on->toDateString(),
                'ends_on' => $freeze->ends_on->toDateString(),
                'membership_ends_on' => $membership->ends_on->toDateString(),
            ],
            data: [
                'membership_id' => (string) $membership->id,
            ],
        );
    }

    public function databaseType(object $notifiable): string
    {
        return 'staff_member_froze';
    }

    public function topic(): StaffNotificationTopic
    {
        return StaffNotificationTopic::AppFreezes;
    }
}
