<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Domains\Announcements\Models\Announcement;
use App\Domains\Members\CurrentMember;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Me\MyAnnouncementResource;

class MyAnnouncementController extends Controller
{
    /**
     * An announcement the gym sent the signed-in member, in full, for when the
     * app opens it from the inbox or a push. Announcements the member didn't
     * receive aren't shown, as if they didn't exist.
     */
    public function show(CurrentMember $currentMember, int $announcement): MyAnnouncementResource
    {
        $received = $currentMember->get()->notifications()
            ->where('type', 'announcement')
            ->where('data->data->announcement_id', (string) $announcement)
            ->exists();

        abort_unless($received, 404);

        return new MyAnnouncementResource(Announcement::query()->whereNotNull('sent_at')->findOrFail($announcement));
    }
}
