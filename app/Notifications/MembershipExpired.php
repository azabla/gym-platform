<?php

namespace App\Notifications;

use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Notifications\Enums\NotificationTopic;
use App\Domains\Notifications\Sms\SendableBySms;
use App\Domains\Notifications\Sms\SmsChannel;

/**
 * The member's membership ended yesterday, and nothing later is sold. It goes
 * by SMS too, for members without the app.
 */
final class MembershipExpired extends MemberNotification implements SendableBySms
{
    public static function for(Membership $membership): self
    {
        return new self(
            details: [
                'plan' => $membership->plan->name,
                'gym' => $membership->organization->name,
                'ends_on' => $membership->ends_on->toDateString(),
            ],
            data: [
                'membership_id' => (string) $membership->id,
            ],
        );
    }

    public function via(Member $member): array
    {
        return [...parent::via($member), SmsChannel::class];
    }

    public function toSms(object $notifiable): string
    {
        return MessageText::sms('membership_expired', $this->details, (string) config('app.fallback_locale'));
    }

    public function databaseType(object $notifiable): string
    {
        return 'membership_expired';
    }

    protected function topic(): NotificationTopic
    {
        return NotificationTopic::Membership;
    }
}
