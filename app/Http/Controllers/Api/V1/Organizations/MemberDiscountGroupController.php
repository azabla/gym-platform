<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\DiscountGroup;
use App\Domains\Memberships\Models\MemberDiscount;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DiscountGroups\AssignDiscountGroupRequest;
use App\Http\Resources\V1\MemberDiscountResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class MemberDiscountGroupController extends Controller
{
    /**
     * Put the member in a discount group, until the day given (included),
     * in place of the group they were in (ADR 0032).
     */
    public function update(AssignDiscountGroupRequest $request, Member $member, CurrentStaff $currentStaff): JsonResponse
    {
        $memberDiscount = MemberDiscount::assign($member, $request->discountGroup(), $request->until(), $currentStaff->get());

        return (new MemberDiscountResource($memberDiscount->load('discountGroup')))->response()->setStatusCode(Response::HTTP_OK);
    }

    /**
     * Take the member out of their discount group.
     */
    public function destroy(Member $member): Response
    {
        Gate::authorize('assign', DiscountGroup::class);

        MemberDiscount::remove($member);

        return response()->noContent();
    }
}
