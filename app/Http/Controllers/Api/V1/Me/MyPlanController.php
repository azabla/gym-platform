<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Domains\Members\CurrentMember;
use App\Domains\Memberships\Exceptions\PromoCodeRefused;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Memberships\Models\PromoCode;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Me\MyPlanResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MyPlanController extends Controller
{
    /**
     * The plans the signed-in member can buy in the app: those on sale at
     * their home branch, cheapest first, each with the dates it would run.
     * With ?promo_code=, each plan also has its price with the code, or null
     * where the code doesn't apply; a code the member can't use is a 422
     * on promo_code (ADR 0030).
     */
    public function index(Request $request, CurrentMember $currentMember): JsonResponse
    {
        $member = $currentMember->get();
        $today = $member->organization->today();
        $startsOn = Membership::firstFreeDayFor($member, $today);
        $promoCode = null;

        if ($request->filled('promo_code')) {
            $promoCode = PromoCode::findByCode($request->string('promo_code')->toString()) ?? throw PromoCodeRefused::unknown();
            $refusal = $promoCode->refusalFor($member, $today);

            if ($refusal !== null) {
                throw $refusal;
            }
        }

        $plans = Plan::query()
            ->onSaleAt($member->home_branch_id)
            ->orderBy('price_minor')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $plans->map(fn (Plan $plan): array => (new MyPlanResource($plan, $startsOn, $promoCode))->resolve($request))->all(),
        ]);
    }
}
