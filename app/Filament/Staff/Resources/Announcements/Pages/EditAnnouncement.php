<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\Announcements\Pages;

use App\Domains\Announcements\Exceptions\AnnouncementRefused;
use App\Domains\Announcements\Models\Announcement;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Filament\Staff\Resources\Announcements\AnnouncementResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Change an announcement that hasn't gone out: what it says, who it goes to,
 * or when, always a time in the future.
 */
class EditAnnouncement extends EditRecord
{
    protected static string $resource = AnnouncementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AnnouncementResource::cancelAction(),
        ];
    }

    /**
     * @param  Announcement  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Announcement
    {
        $branch = AnnouncementResource::branchIn($data);
        $sendAt = AnnouncementResource::sendAtIn($data);
        $refusal = Announcement::refusalToPost(app(CurrentStaff::class)->get(), $branch, $sendAt);

        if ($refusal !== null) {
            throw AnnouncementResource::refusalOnTheForm($refusal);
        }

        $edited = $record->edit([
            'title' => (string) $data['title'],
            'body' => (string) $data['body'],
            'branch_id' => $branch?->id,
            'include_recently_expired' => (bool) ($data['include_recently_expired'] ?? false),
            ...($sendAt === null ? [] : ['send_at' => $sendAt]),
        ]);

        if (! $edited) {
            throw AnnouncementResource::refusalOnTheForm(AnnouncementRefused::alreadyGoneOut());
        }

        return $record;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
