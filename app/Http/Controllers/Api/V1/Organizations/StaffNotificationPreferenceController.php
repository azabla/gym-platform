<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Organizations\UpdateStaffNotificationPreferencesRequest;
use Illuminate\Http\JsonResponse;

class StaffNotificationPreferenceController extends Controller
{
    /**
     * Whether each topic of the signed-in staff member's messages at this gym
     * also comes by push (on until turned off) and by SMS (off until turned
     * on, and never between 21:00 and 07:00). The inbox gets everything. See
     * ADR 0035.
     */
    public function show(CurrentStaff $currentStaff): JsonResponse
    {
        return response()->json(['data' => $currentStaff->get()->notificationChoices()]);
    }

    /**
     * Turn push or SMS on or off for any of the topics, and answer with all of them.
     */
    public function update(UpdateStaffNotificationPreferencesRequest $request, CurrentStaff $currentStaff): JsonResponse
    {
        $staff = $currentStaff->get();
        $staff->chooseNotifications($request->choices());

        return response()->json(['data' => $staff->notificationChoices()]);
    }
}
