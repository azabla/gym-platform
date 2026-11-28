<?php

declare(strict_types=1);

namespace App\Domains\Memberships\Models;

use App\Domains\Activity\Activity;
use App\Domains\Activity\Enums\ActivityAction;
use App\Domains\Members\Models\Member;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * A member's place in a discount group, optionally until a day (a student
 * card's expiry, say), and who put them there. A member is in one group at
 * most. Kept in Memberships, so the Members domain knows nothing of prices.
 * See ADR 0032.
 */
class MemberDiscount extends Model
{
    use BelongsToOrganization;

    /**
     * Put the member in the group, until the day given (included), replacing
     * the group they were in, if any.
     */
    public static function assign(Member $member, DiscountGroup $group, ?CarbonImmutable $until, Staff $assignedBy): self
    {
        $memberDiscount = self::query()->where('member_id', $member->id)->first() ?? new self;
        $memberDiscount->organization()->associate($member->organization_id);
        $memberDiscount->member()->associate($member);
        $memberDiscount->discountGroup()->associate($group);
        $memberDiscount->until = $until;
        $memberDiscount->assignedBy()->associate($assignedBy);

        DB::transaction(function () use ($memberDiscount, $member, $group, $until, $assignedBy): void {
            $memberDiscount->save();

            Activity::record(ActivityAction::DiscountGiven, $member, $assignedBy, $member->home_branch_id, [
                'group' => $group->name,
                'discount' => $group->discount()->reason,
                'until' => $until,
            ]);
        });

        return $memberDiscount;
    }

    /**
     * Take the member out of their group, if they are in one.
     */
    public static function remove(Member $member, ?Staff $removedBy = null): void
    {
        DB::transaction(function () use ($member, $removedBy): void {
            $memberDiscount = self::of($member);

            if ($memberDiscount === null) {
                return;
            }

            $memberDiscount->delete();

            Activity::record(ActivityAction::DiscountRemoved, $member, $removedBy, $member->home_branch_id, [
                'group' => $memberDiscount->discountGroup()->withTrashed()->first()?->name,
            ]);
        });
    }

    public static function of(Member $member): ?self
    {
        return self::query()->with('discountGroup')->where('member_id', $member->id)->first();
    }

    /**
     * The member's group on the day, if it still applies: they are in one,
     * its until hasn't passed, and it isn't archived.
     */
    public static function groupFor(Member $member, CarbonImmutable $today): ?DiscountGroup
    {
        $memberDiscount = self::of($member);

        if ($memberDiscount === null || ($memberDiscount->until !== null && $memberDiscount->until->lessThan($today))) {
            return null;
        }

        return $memberDiscount->discountGroup;
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withTrashed();
    }

    /**
     * The group; archived groups aren't found, so they no longer apply.
     *
     * @return BelongsTo<DiscountGroup, $this>
     */
    public function discountGroup(): BelongsTo
    {
        return $this->belongsTo(DiscountGroup::class);
    }

    /** @return BelongsTo<Staff, $this> */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'assigned_by_staff_id')->withTrashed();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'until' => 'immutable_date',
        ];
    }
}
