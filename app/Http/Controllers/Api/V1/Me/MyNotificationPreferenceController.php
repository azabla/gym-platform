<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Domains\Members\CurrentMember;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Me\UpdateNotificationPreferencesRequest;
use Illuminate\Http\JsonResponse;

class MyNotificationPreferenceController extends Controller
{
    /**
     * Which topics the member gets pushes about at this gym. Every topic is
     * on until turned off. They decide pushes only: the inbox gets everything.
     */
    public function show(CurrentMember $currentMember): JsonResponse
    {
        return response()->json(['data' => $currentMember->get()->pushPreferences()]);
    }

    /**
     * Turn pushes about any of the topics on or off, and answer with all of them.
     */
    public function update(UpdateNotificationPreferencesRequest $request, CurrentMember $currentMember): JsonResponse
    {
        $member = $currentMember->get();
        $member->choosePushes($request->choices());

        return response()->json(['data' => $member->pushPreferences()]);
    }
}
