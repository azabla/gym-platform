<?php

namespace App\Notifications;

use App\Domains\Announcements\Models\Announcement;
use App\Domains\Notifications\Enums\NotificationTopic;
use App\Domains\Notifications\Push\PushMessage;
use Illuminate\Support\Str;

/**
 * An announcement from the gym, in the gym's own words, so its title and body
 * are the same in every language. The push shows the start of a long body;
 * the inbox keeps all of it. See ADR 0015.
 */
final class AnnouncementMade extends MemberNotification
{
    /**
     * How much of the body a push shows.
     */
    private const PUSH_BODY_LENGTH = 150;

    public static function for(Announcement $announcement): self
    {
        return new self(
            details: [
                'title' => $announcement->title,
                'body' => $announcement->body,
                'gym' => $announcement->organization->name,
            ],
            data: [
                'announcement_id' => (string) $announcement->id,
            ],
        );
    }

    public function databaseType(object $notifiable): string
    {
        return 'announcement';
    }

    public function toPush(object $notifiable, string $locale): PushMessage
    {
        $push = parent::toPush($notifiable, $locale);

        return new PushMessage($push->title, Str::limit($push->body, self::PUSH_BODY_LENGTH), $push->data);
    }

    protected function topic(): NotificationTopic
    {
        return NotificationTopic::Announcements;
    }
}
