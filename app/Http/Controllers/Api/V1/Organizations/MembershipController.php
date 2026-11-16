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
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->paginate(25);

        MembershipResource::loadDetails(...$memberships->items());

        return MembershipResource::collection($memberships);
    }

    /**
     * Sell a plan to the member, starting on the given day or the first free day,
     * at a typed discount if one is given, or else at the member's discount
     * group's or the promo code's price, whichever is lower.
     */
    public function store(StoreMembershipRequest $request, Member $member, CurrentStaff $currentStaff): MembershipResource
    {
        $discount = $request->discount();

        // A typed discount (owners and managers) is used as it is; otherwise the member
        // pays the lowest of the plan's price, their discount group's and the code's (ADR 0032).
        $membership = $discount !== null
            ? Membership::sell($member, $request->plan(), $request->startsOn(), $currentStaff->get(), $discount)
            : Membership::sellAt($request->priceChoice(), $member, $request->plan(), $request->startsOn(), $currentStaff->get());
        MembershipResource::loadDetails($membership);

        return new MembershipResource($membership);
    }

    /**
     * One membership of the gym.
     */
    public function show(Membership $membership): MembershipResource
    {
        Gate::authorize('view', $membership);

        MembershipResource::loadDetails($membership);

        return new MembershipResource($membership);
    }
}
