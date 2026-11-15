<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\StaffNotificationResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The signed-in staff member's inbox at the gym: online payments, refunds
 * needed, freezes made in the app and the morning list of members to call
 * (ADR 0027).
 */
class StaffNotificationController extends Controller
{
    /**
     * Their messages at this gym, newest first, 25 per page, with the number
     * of unread ones across every page.
     */
    public function index(CurrentStaff $currentStaff): AnonymousResourceCollection
    {
        $staff = $currentStaff->get();

        return StaffNotificationResource::collection($staff->notifications()->paginate(25))->additional([
            'meta' => ['unread_count' => $staff->unreadNotifications()->count()],
        ]);
    }

    /**
     * Mark one message read. Marking it again changes nothing.
     */
    public function read(CurrentStaff $currentStaff, string $notification): Response
    {
        $currentStaff->get()->notifications()->whereKey($notification)->firstOrFail()->markAsRead();

        return response()->noContent();
    }

    /**
     * Mark every message at this gym read.
     */
    public function readAll(CurrentStaff $currentStaff): Response
    {
        $currentStaff->get()->unreadNotifications()->update(['read_at' => now()]);

        return response()->noContent();
    }
}
