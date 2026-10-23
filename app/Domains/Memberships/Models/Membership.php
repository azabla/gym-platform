<?php

declare(strict_types=1);

namespace App\Domains\Memberships\Models;

use App\Casts\AsMoney;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Enums\MembershipStatus;
use App\Domains\Memberships\Policies\MembershipPolicy;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\MembershipFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A plan sold to a member for set dates, from starts_on through ends_on. The
 * price is copied from the plan when it is sold. A member has at most one
 * membership on any day (cancelled ones don't count), which the database
 * enforces. See ADR 0006.
 */
#[UseFactory(MembershipFactory::class)]
#[UsePolicy(MembershipPolicy::class)]
class Membership extends Model
{
    /** @use HasFactory<MembershipFactory> */
    use BelongsToOrganization, HasFactory;

    /**
     * Sell the plan to the member, starting on the given day. The membership
     * belongs to the member's gym, and its end date and price come from the plan.
     */
    public static function sell(Member $member, Plan $plan, CarbonImmutable $startsOn, Staff $soldBy): self
    {
        $membership = new self;
        $membership->organization()->associate($member->organization_id);
        $membership->member()->associate($member);
        $membership->plan()->associate($plan);
        $membership->soldBy()->associate($soldBy);
        $membership->starts_on = $startsOn;
        $membership->ends_on = $plan->endsOnFor($startsOn);
        $membership->price = $plan->price;
        $membership->save();

        return $membership;
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * The plan that was sold, even if it has been archived since.
     *
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class)->withTrashed();
    }

    /** @return BelongsTo<Staff, $this> */
    public function soldBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'sold_by_staff_id');
    }

    /** @return BelongsTo<Staff, $this> */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'cancelled_by_staff_id');
    }

    public function statusOn(CarbonImmutable $day): MembershipStatus
    {
        return match (true) {
            $this->cancelled_at !== null => MembershipStatus::Cancelled,
            $this->starts_on->isAfter($day) => MembershipStatus::Upcoming,
            $this->ends_on->isBefore($day) => MembershipStatus::Expired,
            default => MembershipStatus::Active,
        };
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'price' => AsMoney::class.':price_minor,currency',
            'cancelled_at' => 'immutable_datetime',
        ];
    }
}
