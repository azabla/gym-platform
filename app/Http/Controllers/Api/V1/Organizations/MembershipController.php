<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Memberships\StoreMembershipRequest;
use App\Http\Resources\V1\MembershipResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class MembershipController extends Controller
{
    /**
     * The member's memberships, newest first, 25 per page.
     */
    public function index(Member $member): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Membership::class);

        $memberships = Membership::query()
            ->whereBelongsTo($member)
            ->with(['plan', 'soldBy.user', 'cancelledBy.user'])
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->paginate(25);

        return MembershipResource::collection($memberships);
    }

    /**
     * Sell a plan to the member, starting on the given day or the first free day.
     */
    public function store(StoreMembershipRequest $request, Member $member, CurrentStaff $currentStaff): MembershipResource
    {
        $membership = Membership::sell($member, $request->plan(), $request->startsOn(), $currentStaff->get());

        return new MembershipResource($membership->load(['plan', 'soldBy.user']));
    }

    /**
     * One membership of the gym.
     */
    public function show(Membership $membership): MembershipResource
    {
        Gate::authorize('view', $membership);

        return new MembershipResource($membership->load(['plan', 'soldBy.user', 'cancelledBy.user']));
    }
}
