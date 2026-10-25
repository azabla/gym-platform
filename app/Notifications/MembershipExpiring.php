<?php

namespace App\Notifications;

use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Notifications\Enums\NotificationTopic;
use App\Domains\Notifications\Sms\SendableBySms;
use App\Domains\Notifications\Sms\SmsChannel;

/**
 * The member's membership ends in a few days (5, 3 or 1), and nothing later
 * is sold yet. At 3 days it goes by SMS too, for members without the app.
 */
final class MembershipExpiring extends MemberNotification implements SendableBySms
{
    /**
     * The reminder that also goes by SMS: one SMS, not three.
     */
    public const SMS_DAYS_BEFORE_END = 3;

    private int $daysLeft = 0;

    public static function for(Membership $membership, int $daysLeft): self
    {
        $notification = new self(
            details: [
                'plan' => $membership->plan->name,
                'gym' => $membership->organization->name,
                'ends_on' => $membership->ends_on->toDateString(),
                'count' => $daysLeft,
            ],
            data: [
                'membership_id' => (string) $membership->id,
            ],
        );
        $notification->daysLeft = $daysLeft;

        return $notification;
    }

    public function via(Member $member): array
    {
        $channels = parent::via($member);

        return $this->daysLeft === self::SMS_DAYS_BEFORE_END ? [...$channels, SmsChannel::class] : $channels;
    }

    public function toSms(object $notifiable): string
    {
        return MessageText::sms('membership_expiring', $this->details, (string) config('app.fallback_locale'));
    }

    public function databaseType(object $notifiable): string
    {
        return 'membership_expiring';
    }

    protected function topic(): NotificationTopic
    {
        return NotificationTopic::Membership;
    }
}
