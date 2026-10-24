<?php

namespace App\Http\Resources\V1\Me;

use App\Domains\Billing\Balances\MembershipBalance;
use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\MembershipFreeze;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Http\Resources\V1\BranchResource;
use App\Http\Resources\V1\MembershipResource;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One of the signed-in member's memberships, for the members app. It leaves
 * out what is for staff only: the discount and cancellation reasons, why a
 * freeze was given, and which staff member sold, froze or cancelled it.
 *
 * @mixin Membership
 */
class MyMembershipResource extends JsonResource
{
    /**
     * Load everything the response shows: the plan, branches and freezes, and
     * from Billing the balance and the open payment link. One query per
     * relation, however many memberships.
     */
    public static function loadDetails(Membership ...$memberships): void
    {
        (new Collection($memberships))->load(['plan', 'branches', 'freezes']);
        MembershipResource::attachBalances(...$memberships);

        $openPaymentLinks = PaymentLink::query()
            ->pending()
            ->whereIn('membership_id', array_map(fn (Membership $membership): int => $membership->id, $memberships))
            ->get()
            ->keyBy('membership_id');

        foreach ($memberships as $membership) {
            $membership->setRelation('openPaymentLink', $openPaymentLinks->get($membership->id));
        }
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
            'plan' => [
                'id' => $this->plan->id,
                'name' => $this->plan->name,
            ],
            'status' => $this->statusOn(app(CurrentOrganization::class)->get()->today()),
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on->toDateString(),
            'price' => $this->price,
            'amount_paid' => $this->whenLoaded('balance', fn (MembershipBalance $balance) => $balance->amountPaid),
            'balance_due' => $this->whenLoaded('balance', fn (MembershipBalance $balance) => $balance->balanceDue),
            'valid_at_all_branches' => $this->valid_at_all_branches,
            'branches' => BranchResource::collection($this->whenLoaded('branches')),
            'freeze_days_allowed' => $this->freeze_days_allowed,
            'freeze_days_used' => $this->whenLoaded('freezes', fn () => $this->freezeDaysUsed()),
            'freeze_days_left' => $this->whenLoaded('freezes', fn () => max(0, $this->freeze_days_allowed - $this->freezeDaysUsed())),
            'freezes' => $this->whenLoaded('freezes', fn () => $this->freezes->map(fn (MembershipFreeze $freeze): array => [
                'starts_on' => $freeze->starts_on->toDateString(),
                'ends_on' => $freeze->ends_on->toDateString(),
                'days' => $freeze->days(),
            ])->all()),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'open_payment_link' => $this->whenLoaded(
                'openPaymentLink',
                fn (PaymentLink $paymentLink) => new MyPaymentLinkResource($paymentLink),
            ),
        ];
    }
}
