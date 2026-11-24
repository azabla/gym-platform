<?php

namespace App\Notifications;

use App\Domains\Notifications\Enums\NotificationTopic;
use App\Domains\Organizations\Models\BranchClosure;

/**
 * The member's home branch will be closed, or open other hours, on some days
 * soon, such as a holiday (ADR 0037). It goes to the inbox and by push, as
 * "branch_closed" or "branch_hours_changed".
 */
final class BranchClosing extends MemberNotification
{
    public static function for(BranchClosure $closure): self
    {
        $details = [
            'branch' => (string) $closure->branch->name,
            'gym' => (string) $closure->organization->name,
            'reason' => (string) $closure->reason,
            'starts_on' => $closure->starts_on->toDateString(),
            'ends_on' => $closure->ends_on->toDateString(),
            'count' => (int) $closure->starts_on->diffInDays($closure->ends_on) + 1,
        ];

        if (! $closure->isClosedAllDay()) {
            $details['opens_at'] = substr((string) $closure->opens_at, 0, 5);
            $details['closes_at'] = substr((string) $closure->closes_at, 0, 5);
        }

        return new self(
            details: $details,
            data: [
                'branch_id' => (string) $closure->branch_id,
            ],
        );
    }

    public function databaseType(object $notifiable): string
    {
        return isset($this->details['opens_at']) ? 'branch_hours_changed' : 'branch_closed';
    }

    protected function topic(): NotificationTopic
    {
        return NotificationTopic::Announcements;
    }
}
