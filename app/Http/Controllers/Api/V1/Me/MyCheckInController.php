<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Domains\Attendance\Models\CheckIn;
use App\Domains\Members\CurrentMember;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Me\MyCheckInResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MyCheckInController extends Controller
{
    /**
     * The signed-in member's visits to the gym, allowed and denied, newest
     * first, 25 per page.
     */
    public function index(CurrentMember $currentMember): AnonymousResourceCollection
    {
        $checkIns = CheckIn::query()
            ->whereBelongsTo($currentMember->get())
            ->with(MyCheckInResource::RELATIONS)
            ->orderByDesc('checked_in_at')
            ->orderByDesc('id')
            ->paginate(25);

        return MyCheckInResource::collection($checkIns);
    }
}
