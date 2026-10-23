<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\MembershipFreeze;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Memberships\EndFreezeRequest;
use App\Http\Requests\Api\V1\Memberships\FreezeMembershipRequest;
use App\Http\Resources\V1\MembershipResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class MembershipFreezeController extends Controller
{
    /**
     * Freeze the membership. Its end date, and that of any membership queued
     * after it, moves later by the number of frozen days. Responds with the
     * membership.
     */
    public function store(FreezeMembershipRequest $request, Membership $membership, CurrentStaff $currentStaff): JsonResponse
    {
        $membership->freeze($request->startsOn(), $request->endsOn(), $request->string('reason')->toString(), $currentStaff->get());
        MembershipResource::loadDetails($membership);

        return (new MembershipResource($membership))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * End the freeze early, because the member is back today.
     */
    public function end(EndFreezeRequest $request, Membership $membership, MembershipFreeze $freeze, CurrentOrganization $currentOrganization): MembershipResource
    {
        $membership->endFreeze($freeze, $currentOrganization->get()->today());
        MembershipResource::loadDetails($membership);

        return new MembershipResource($membership);
    }
}
