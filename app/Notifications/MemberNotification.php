<?php

namespace App\Notifications;

use App\Domains\Members\Models\Member;
use App\Domains\Notifications\Enums\NotificationTopic;
use App\Domains\Notifications\Push\PushChannel;
use App\Domains\Notifications\Push\PushMessage;
use App\Domains\Notifications\Push\SendableByPush;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * A message to a member at one gym (ADR 0012). It always goes to the inbox,
 * and by push when the member wants pushes about its topic. Its details are
 * worked out when it is created, at the gym, so the queue worker sending it
 * later queries nothing scoped to a gym. The text is written per language by
 * MessageText.
 */
abstract class MemberNotification extends Notification implements SendableByPush, ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $details  what the message's text is filled in with
     * @param  array<string, string>  $data  what the app needs to open the right screen
     */
    public function __construct(
        protected readonly array $details,
        protected readonly array $data,
    ) {
        $this->afterCommit();
    }

    /**
     * The message's type, as the members app knows it, such as "payment_succeeded".
     */
    abstract public function databaseType(object $notifiable): string;

    abstract protected function topic(): NotificationTopic;

    /**
     * @return list<string|class-string>
     */
    public function via(Member $member): array
    {
        return $member->wantsPushesAbout($this->topic()) ? ['database', PushChannel::class] : ['database'];
    }

    /**
     * What the inbox keeps: not the text, which is written in the language
     * the app asks for, but what it is written from.
     *
     * @return array{details: array<string, mixed>, data: array<string, string>}
     */
    public function toArray(Member $member): array
    {
        return ['details' => $this->details, 'data' => $this->data];
    }

    public function toPush(object $notifiable, string $locale): PushMessage
    {
        $type = $this->databaseType($notifiable);

        return new PushMessage(
            MessageText::title($type, $this->details, $locale),
            MessageText::body($type, $this->details, $locale),
            [
                ...$this->data,
                'type' => $type,
                'notification_id' => (string) $this->id,
                'organization_id' => $notifiable instanceof Member ? (string) $notifiable->organization_id : '',
            ],
        );
    }
}
