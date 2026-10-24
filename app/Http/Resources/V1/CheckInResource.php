<?php

namespace App\Http\Resources\V1;

use App\Domains\Attendance\Enums\CheckInResult;
use App\Domains\Attendance\Models\CheckIn;
use App\Domains\Billing\Balances\MembershipBalance;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Shared\Money\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A check-in, as the desk sees it: the decision and why, who came, with which
 * membership, and what they still owe on it, so the desk can ask.
 *
 * @mixin CheckIn
 */
class CheckInResource extends JsonResource
{
    private const RELATIONS = ['branch', 'member', 'membership.plan', 'checkedInBy.user'];

    /**
     * Load everything a check-in response shows: the relations above and the
     * balance of the membership, from Billing.
     */
    public static function loadDetails(CheckIn ...$checkIns): void
    {
        (new Collection($checkIns))->load(self::RELATIONS);

        MembershipResource::attachBalances(...array_values(array_filter(
            array_map(fn (CheckIn $checkIn): ?Membership => $checkIn->membership, $checkIns),
        )));
    }

    /**
     * Transform the resource into an array, once loadDetails() ran.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'decision' => $this->result === CheckInResult::Allowed ? 'allow' : 'deny',
            'reason_code' => $this->reason_code,
            'method' => $this->method,
            'checked_in_at' => $this->checked_in_at->toIso8601String(),
            'branch' => new BranchResource($this->whenLoaded('branch')),
            'member' => $this->whenLoaded('member', fn (Member $member) => [
                'id' => $member->id,
                'full_name' => $member->full_name,
                'phone' => $member->phone,
            ]),
            'membership' => $this->whenLoaded('membership', fn (Membership $membership) => [
                'id' => $membership->id,
                'plan' => [
                    'id' => $membership->plan->id,
                    'name' => $membership->plan->name,
                ],
                'starts_on' => $membership->starts_on->toDateString(),
                'ends_on' => $membership->ends_on->toDateString(),
                'balance_due' => $this->balanceDue($membership),
            ]),
            'checked_in_by' => $this->whenLoaded('checkedInBy', fn (Staff $staff) => [
                'id' => $staff->id,
                'name' => $staff->user->name,
            ]),
        ];
    }

    /**
     * What is still owed on the membership, once loadDetails() attached it.
     */
    private function balanceDue(Membership $membership): ?Money
    {
        $balance = $membership->relationLoaded('balance') ? $membership->getRelation('balance') : null;

        return $balance instanceof MembershipBalance ? $balance->balanceDue : null;
    }
}
