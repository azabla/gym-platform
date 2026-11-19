<?php

namespace App\Notifications;

use App\Domains\Notifications\Enums\StaffNotificationTopic;
use App\Domains\Notifications\Push\PushChannel;
use App\Domains\Notifications\Push\PushMessage;
use App\Domains\Notifications\Push\SendableByPush;
use App\Domains\Notifications\Sms\SendableBySms;
use App\Domains\Notifications\Sms\SmsChannel;
use App\Domains\Organizations\Models\Staff;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * A message to a staff member at one gym, in their inbox: the staff panel's
 * and the API's (ADR 0027). Like a message to a member, it keeps what its text
 * is written from, not the text, and what to open. Staff messages don't show
 * on a lock screen, so they may name the member.
 *
 * It also goes by push to the staff app, unless the staff member turned that
 * off for its topic, and by SMS when they turned that on, but never at night
 * where the gym is. Push and SMS are written in the staff member's message
 * language. See ADR 0035.
 */
abstract class StaffNotification extends Notification implements SendableByPush, SendableBySms, ShouldQueue
{
    use Queueable;

    /**
     * Staff get texts from this hour, where the gym is.
     */
    public const TEXTS_FROM_HOUR = 7;

    /**
     * ... and until this hour.
     */
    public const TEXTS_UNTIL_HOUR = 21;

    /**
     * @param  array<string, mixed>  $details  what the message's text is filled in with
     * @param  array<string, string>  $data  what to open: a payment_id, membership_id or member_id
     */
    public function __construct(
        protected readonly array $details,
        protected readonly array $data,
    ) {
        $this->afterCommit();
    }

    /**
     * The message's type, such as "staff_online_payment".
     */
    abstract public function databaseType(object $notifiable): string;

    abstract public function topic(): StaffNotificationTopic;

    /**
     * @return list<string|class-string>
     */
    public function via(Staff $staff): array
    {
        $channels = ['database'];

        if ($staff->wantsPushesAbout($this->topic())) {
            $channels[] = PushChannel::class;
        }

        if ($staff->wantsTextsAbout($this->topic()) && self::isDaytimeAt($staff)) {
            $channels[] = SmsChannel::class;
        }

        return $channels;
    }

    /**
     * @return array{details: array<string, mixed>, data: array<string, string>}
     */
    public function toArray(Staff $staff): array
    {
        return ['details' => $this->details, 'data' => $this->data];
    }

    /**
     * The push, in the staff member's message language whatever the phone's,
     * like their inbox (ADR 0034).
     */
    public function toPush(object $notifiable, string $locale): PushMessage
    {
        $type = $this->databaseType($notifiable);
        $language = self::languageOf($notifiable);

        return new PushMessage(
            MessageText::forStaff($type, 'title', $this->details, $language),
            MessageText::forStaff($type, 'body', $this->details, $language),
            [
                ...$this->data,
                'type' => $type,
                'notification_id' => (string) $this->id,
                'gym' => $notifiable instanceof Staff ? (string) $notifiable->organization->slug : '',
            ],
        );
    }

    /**
     * The SMS, which starts with the gym's name: the same person may work at
     * two gyms.
     */
    public function toSms(object $notifiable): string
    {
        $gym = $notifiable instanceof Staff ? (string) $notifiable->organization->name : '';

        return MessageText::forStaff($this->databaseType($notifiable), 'sms', [...$this->details, 'gym' => $gym], self::languageOf($notifiable));
    }

    private static function isDaytimeAt(Staff $staff): bool
    {
        $hour = CarbonImmutable::now($staff->organization->timezone)->hour;

        return $hour >= self::TEXTS_FROM_HOUR && $hour < self::TEXTS_UNTIL_HOUR;
    }

    private static function languageOf(object $notifiable): string
    {
        $user = $notifiable instanceof Staff ? $notifiable->user : null;

        return $user === null ? 'en' : $user->messageLanguage()->value;
    }
}
