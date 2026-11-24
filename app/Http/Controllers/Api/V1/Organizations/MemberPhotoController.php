<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Domains\Members\Models\Member;
use App\Domains\Members\Photos\MemberPhoto;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Members\UpdateMemberPhotoRequest;
use App\Http\Resources\V1\MemberResource;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class MemberPhotoController extends Controller
{
    public function __construct(private MemberPhoto $memberPhoto) {}

    /**
     * Take or replace the member's photo, sent as multipart "photo". It is
     * kept as a 512 px square, upright and without its EXIF, and shown
     * through links that work for 10 minutes. See ADR 0036.
     */
    public function update(UpdateMemberPhotoRequest $request, Member $member): MemberResource
    {
        $this->memberPhoto->store($member, $request->photoBytes());

        return new MemberResource($member->load('homeBranch'));
    }

    /**
     * Take the member's photo away.
     */
    public function destroy(Member $member): Response
    {
        Gate::authorize('update', $member);

        $this->memberPhoto->remove($member);

        return response()->noContent();
    }
}
