<?php

namespace App\Notifications;

use App\Domains\Memberships\Models\Membership;
use App\Domains\Notifications\Enums\NotificationTopic;

/**
 * The member's visit pack has one visit left, so they can renew before
 * they are turned away. See ADR 0016.
 */
final class VisitsRunningLow extends MemberNotification
{
    public static function for(Membership $membership): self
    {
        return new self(
            details: [
                'plan' => $membership->plan->name,
                'gym' => $membership->organization->name,
            ],
            data: [
                'membership_id' => (string) $membership->id,
            ],
        );
    }

    public function databaseType(object $notifiable): string
    {
        return 'visits_running_low';
    }

    protected function topic(): NotificationTopic
    {
        return NotificationTopic::Membership;
    }
}
