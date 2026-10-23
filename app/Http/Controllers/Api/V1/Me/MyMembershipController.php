<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Domains\Members\CurrentMember;
use App\Domains\Memberships\Models\Membership;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Me\MyMembershipResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MyMembershipController extends Controller
{
    /**
     * The signed-in member's memberships at the gym, newest first.
     */
    public function index(CurrentMember $currentMember): AnonymousResourceCollection
    {
        $memberships = Membership::query()
            ->whereBelongsTo($currentMember->get())
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->get();

        MyMembershipResource::loadDetails(...$memberships->all());

        return MyMembershipResource::collection($memberships);
    }

    /**
     * One of the signed-in member's memberships at the gym.
     */
    public function show(CurrentMember $currentMember, int $membership): MyMembershipResource
    {
        $myMembership = Membership::query()->whereBelongsTo($currentMember->get())->findOrFail($membership);

        MyMembershipResource::loadDetails($myMembership);

        return new MyMembershipResource($myMembership);
    }
}
