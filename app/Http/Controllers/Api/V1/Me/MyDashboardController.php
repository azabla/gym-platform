<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Domains\Attendance\Models\CheckIn;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Members\CurrentMember;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\MembershipFreeze;
use App\Domains\Shared\Money\Money;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Me\MyCheckInResource;
use App\Http\Resources\V1\Me\MyMembershipResource;
use App\Http\Resources\V1\Me\MyPaymentLinkResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MyDashboardController extends Controller
{
    /**
     * How many recent visits the home screen shows.
     */
    private const RECENT_CHECK_INS = 5;

    /**
     * Everything the members app's home screen shows, in one call: the
     * membership that best shows where the member stands today, the one
     * after it if already sold, the last few visits, what they owe, any open
     * payment link, and the unread messages. See ADR 0014.
     */
    public function __invoke(Request $request, CurrentMember $currentMember): JsonResponse
    {
        $member = $currentMember->get();
        $today = $member->organization->today();

        $membership = Membership::atAGlanceFor([$member->id], $today)->get($member->id);
        $nextMembership = $membership === null ? null : Membership::query()
            ->whereBelongsTo($member)
            ->notCancelled()
            ->where('starts_on', '>', $membership->ends_on->toDateString())
            ->orderBy('starts_on')
            ->first();

        MyMembershipResource::loadDetails(...array_values(array_filter([$membership, $nextMembership])));

        $frozenUntil = $membership?->freezes->first(fn (MembershipFreeze $freeze): bool => $freeze->covers($today))?->ends_on;

        $checkIns = CheckIn::query()
            ->whereBelongsTo($member)
            ->with(MyCheckInResource::RELATIONS)
            ->orderByDesc('checked_in_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_CHECK_INS)
            ->get();

        $openPaymentLink = PaymentLink::query()
            ->whereBelongsTo($member)
            ->pending()
            ->with(['payment', 'plan'])
            ->latest('id')
            ->first();

        return response()->json([
            'data' => [
                'membership' => $membership === null ? null : [
                    ...(new MyMembershipResource($membership))->resolve($request),
                    'frozen_until' => $frozenUntil?->toDateString(),
                ],
                'next_membership' => $nextMembership === null ? null : (new MyMembershipResource($nextMembership))->resolve($request),
                'recent_check_ins' => MyCheckInResource::collection($checkIns)->resolve($request),
                'balance_due' => Payment::balancesDueOf([$member->id])[$member->id] ?? Money::zero(),
                'open_payment_link' => $openPaymentLink === null ? null : (new MyPaymentLinkResource($openPaymentLink))->resolve($request),
                'unread_notifications' => $member->unreadNotifications()->count(),
            ],
        ]);
    }
}
