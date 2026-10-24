<?php

namespace App\Notifications;

use App\Domains\Memberships\Models\MembershipFreeze;
use App\Domains\Notifications\Enums\NotificationTopic;

/**
 * The gym froze the member's membership: from when to when, and its new end date.
 */
final class MembershipFrozen extends MemberNotification
{
    public static function for(MembershipFreeze $freeze): self
    {
        $membership = $freeze->membership;

        return new self(
            details: [
                'plan' => $membership->plan->name,
                'gym' => $membership->organization->name,
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
        return 'membership_frozen';
    }

    protected function topic(): NotificationTopic
    {
        return NotificationTopic::Membership;
    }
}
