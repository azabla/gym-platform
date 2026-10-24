<?php

namespace App\Jobs;

use App\Domains\Announcements\Models\Announcement;
use App\Domains\Members\Models\Member;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Notifications\AnnouncementMade;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;

/**
 * Sends a due announcement to its audience, at its gym, in chunks. It claims
 * the announcement first, so it goes out once even if this job runs twice or
 * the announcement was cancelled while it waited. See ADR 0015.
 */
class SendAnnouncement implements ShouldQueueAfterCommit
{
    use Queueable;

    /**
     * How many members are told at a time.
     */
    private const CHUNK_SIZE = 200;

    public function __construct(public Announcement $announcement) {}

    public function handle(CurrentOrganization $currentOrganization): void
    {
        $announcement = $this->announcement;

        $currentOrganization->run($announcement->organization, function () use ($announcement): void {
            if (! $announcement->claimForSending()) {
                return;
            }

            $notification = AnnouncementMade::for($announcement);
            $recipients = 0;

            $announcement->audience()->chunkById(self::CHUNK_SIZE, function (Collection $members) use ($notification, &$recipients): void {
                /** @var Collection<int, Member> $members */
                Notification::send($members, $notification);
                $recipients += $members->count();
            });

            $announcement->recordRecipients($recipients);
        });
    }
}
