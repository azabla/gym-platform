<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Domains\Members\CurrentMember;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Me\MyPlanResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MyPlanController extends Controller
{
    /**
     * The plans the signed-in member can buy in the app: those on sale at
     * their home branch, cheapest first, each with the dates it would run.
     */
    public function index(Request $request, CurrentMember $currentMember): JsonResponse
    {
        $member = $currentMember->get();
        $startsOn = Membership::firstFreeDayFor($member, $member->organization->today());

        $plans = Plan::query()
            ->onSaleAt($member->home_branch_id)
            ->orderBy('price_minor')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $plans->map(fn (Plan $plan): array => (new MyPlanResource($plan, $startsOn))->resolve($request))->all(),
        ]);
    }
}
