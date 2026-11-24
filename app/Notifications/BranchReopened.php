<?php

namespace App\Notifications;

use App\Domains\Notifications\Enums\NotificationTopic;
use App\Domains\Organizations\Models\Branch;
use Carbon\CarbonImmutable;

/**
 * A closure the member was told about was taken back: their home branch is
 * open as usual on those days (ADR 0039). It goes to the inbox and by push,
 * as "branch_reopened".
 */
final class BranchReopened extends MemberNotification
{
    public static function for(Branch $branch, string $startsOn, string $endsOn): self
    {
        return new self(
            details: [
                'branch' => (string) $branch->name,
                'gym' => (string) $branch->organization->name,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'count' => (int) CarbonImmutable::parse($startsOn)->diffInDays(CarbonImmutable::parse($endsOn)) + 1,
            ],
            data: [
                'branch_id' => (string) $branch->id,
            ],
        );
    }

    public function databaseType(object $notifiable): string
    {
        return 'branch_reopened';
    }

    protected function topic(): NotificationTopic
    {
        return NotificationTopic::Announcements;
    }
}
