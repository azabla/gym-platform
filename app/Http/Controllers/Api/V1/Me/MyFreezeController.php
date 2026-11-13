<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Me\EndMyFreezeRequest;
use App\Http\Requests\Api\V1\Me\FreezeMyMembershipRequest;
use App\Http\Resources\V1\Me\MyMembershipResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Members freeze their own memberships in the app, and end a freeze early.
 * See ADR 0026.
 */
class MyFreezeController extends Controller
{
    /**
     * Freeze the membership from the first day through the last. Its end date
     * moves later by the frozen days. The answer is the membership as it is
     * now, with its new end date and freeze days left.
     */
    public function store(FreezeMyMembershipRequest $request): JsonResponse
    {
        $membership = $request->membership();
        $membership->freeze($request->startsOn(), $request->endsOn(), $request->reason(), null);

        MyMembershipResource::loadDetails($membership->refresh());

        return (new MyMembershipResource($membership))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * End the freeze early: a freeze going on today ends yesterday, one still
     * to come is dropped, and the membership's end date moves back.
     */
    public function end(EndMyFreezeRequest $request): MyMembershipResource
    {
        $membership = $request->membership();
        $membership->endFreeze($request->freeze(), $request->today());

        MyMembershipResource::loadDetails($membership->refresh());

        return new MyMembershipResource($membership);
    }
}
