<?php

namespace App\Notifications;

use App\Domains\Attendance\Models\CheckIn;
use App\Domains\Notifications\Enums\NotificationTopic;

/**
 * The member was turned away at the door: where, and why, in their language.
 */
final class VisitDenied extends MemberNotification
{
    public static function for(CheckIn $checkIn): self
    {
        return new self(
            details: [
                'branch' => $checkIn->branch->name,
                'reason' => ['translate' => "notifications.reasons.{$checkIn->reason_code?->value}"],
            ],
            data: [
                'check_in_id' => (string) $checkIn->id,
                'reason_code' => (string) $checkIn->reason_code?->value,
            ],
        );
    }

    public function databaseType(object $notifiable): string
    {
        return 'visit_denied';
    }

    protected function topic(): NotificationTopic
    {
        return NotificationTopic::Visits;
    }
}
