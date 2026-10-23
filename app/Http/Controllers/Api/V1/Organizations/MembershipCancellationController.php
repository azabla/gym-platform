<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Memberships\CancelMembershipRequest;
use App\Http\Resources\V1\MembershipResource;

class MembershipCancellationController extends Controller
{
    /**
     * Cancel the membership, with a reason. It stays on record, and its days
     * are free for another membership.
     */
    public function store(CancelMembershipRequest $request, Membership $membership, CurrentStaff $currentStaff): MembershipResource
    {
        $membership->cancel($currentStaff->get(), $request->string('reason')->toString());

        return new MembershipResource($membership->load(MembershipResource::RELATIONS));
    }
}
