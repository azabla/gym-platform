<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Domains\Members\CurrentMember;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Me\MyNotificationResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class MyNotificationController extends Controller
{
    /**
     * The member's inbox at the gym, newest first, 25 per page, with the
     * number of unread messages across every page for the app's bell.
     */
    public function index(CurrentMember $currentMember): AnonymousResourceCollection
    {
        $member = $currentMember->get();

        return MyNotificationResource::collection($member->notifications()->paginate(25))->additional([
            'meta' => ['unread_count' => $member->unreadNotifications()->count()],
        ]);
    }

    /**
     * Mark one message read, as when the member opens it or taps its push.
     * Marking it again changes nothing.
     */
    public function read(CurrentMember $currentMember, string $notification): Response
    {
        $currentMember->get()->notifications()->whereKey($notification)->firstOrFail()->markAsRead();

        return response()->noContent();
    }

    /**
     * Mark every message at this gym read.
     */
    public function readAll(CurrentMember $currentMember): Response
    {
        $currentMember->get()->unreadNotifications()->update(['read_at' => now()]);

        return response()->noContent();
    }
}
