<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\Announcements\Pages;

use App\Domains\Announcements\Models\Announcement;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Filament\Staff\Resources\Announcements\AnnouncementResource;
use App\Jobs\SendAnnouncement;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

/**
 * Post an announcement, as the API does: now, or at the time chosen.
 */
class CreateAnnouncement extends CreateRecord
{
    protected static string $resource = AnnouncementResource::class;

    private int $audienceCount = 0;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Announcement
    {
        $staff = app(CurrentStaff::class)->get();
        $branch = AnnouncementResource::branchIn($data);
        $sendAt = ($data['send_now'] ?? false) ? null : AnnouncementResource::sendAtIn($data);
        $refusal = Announcement::refusalToPost($staff, $branch, $sendAt);

        if ($refusal !== null) {
            throw AnnouncementResource::refusalOnTheForm($refusal);
        }

        $announcement = Announcement::post(
            postedBy: $staff,
            title: (string) $data['title'],
            body: (string) $data['body'],
            branch: $branch,
            includeRecentlyExpired: (bool) ($data['include_recently_expired'] ?? false),
            sendAt: $sendAt,
        );
        $this->audienceCount = $announcement->audience()->count();

        if ($sendAt === null) {
            SendAnnouncement::dispatch($announcement);
        }

        return $announcement;
    }

    protected function getCreatedNotificationTitle(): string
    {
        return 'It goes to '.$this->audienceCount.' '.Str::plural('member', $this->audienceCount).'.';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
