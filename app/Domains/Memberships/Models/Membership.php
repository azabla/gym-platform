<?php

declare(strict_types=1);

namespace App\Domains\Memberships\Models;

use App\Casts\AsMoney;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Discount\Discount;
use App\Domains\Memberships\Enums\MembershipStatus;
use App\Domains\Memberships\Policies\MembershipPolicy;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\BelongsToOrganization;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Database\Factories\MembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A plan sold to a member for set dates, from starts_on through ends_on. The
 * plan's price, freeze days and the branches it is valid at are copied when it
 * is sold, and owners and managers may sell it at a discount. A member has at
 * most one membership on any day (cancelled ones don't count), which the
 * database enforces. Freezing it moves its end date later. See ADR 0006 and ADR 0007.
 *
 * @property Money $list_price The plan's price on the day of sale (list_price_minor and currency).
 * @property Money $price What the member paid (price_minor and currency).
 */
#[UseFactory(MembershipFactory::class)]
#[UsePolicy(MembershipPolicy::class)]
class Membership extends Model
{
    /** @use HasFactory<MembershipFactory> */
    use BelongsToOrganization, HasFactory;

    /**
     * The same default as the database column, so a new membership knows it before it is saved.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'valid_at_all_branches' => true,
        'freeze_days_allowed' => 0,
    ];

    /**
     * Sell the plan to the member, starting on the given day, at the plan's
     * price less the discount, if any. The membership belongs to the member's
     * gym, and its end date, list price and branches come from the plan.
     */
    public static function sell(Member $member, Plan $plan, CarbonImmutable $startsOn, Staff $soldBy, ?Discount $discount = null): self
    {
        return DB::transaction(function () use ($member, $plan, $startsOn, $soldBy, $discount): self {
            $membership = new self;
            $membership->organization()->associate($member->organization_id);
            $membership->member()->associate($member);
            $membership->plan()->associate($plan);
            $membership->soldBy()->associate($soldBy);
            $membership->starts_on = $startsOn;
            $membership->ends_on = $plan->endsOnFor($startsOn);
            $membership->list_price = $plan->price;
            $membership->price = $discount?->appliedTo($plan->price) ?? $plan->price;
            $membership->discount_reason = $discount?->reason;
            $membership->freeze_days_allowed = $plan->freeze_days_allowed;
            $membership->valid_at_all_branches = $plan->available_at_all_branches;
            $membership->save();

            if (! $plan->available_at_all_branches) {
                $membership->branches()->syncWithPivotValues(
                    $plan->branches->modelKeys(),
                    ['organization_id' => $membership->organization_id],
                );
            }

            return $membership;
        });
    }

    /**
     * The first day a new membership for the member can start: today, or the
     * day after their last membership ends if that is later.
     */
    public static function firstFreeDayFor(Member $member, CarbonImmutable $today): CarbonImmutable
    {
        $lastEndsOn = static::query()->whereBelongsTo($member)->notCancelled()->max('ends_on');

        if ($lastEndsOn === null) {
            return $today;
        }

        $dayAfterLastEnds = CarbonImmutable::parse((string) $lastEndsOn)->addDay();

        return $dayAfterLastEnds->greaterThan($today) ? $dayAfterLastEnds : $today;
    }

    /**
     * For each of the members who have one, the membership that best shows where
     * they stand today: the one valid today, otherwise the next one coming up,
     * otherwise the last one that ended. One query for all the members, keyed by
     * member id.
     *
     * @param  array<int, int>  $memberIds
     * @return Collection<array-key, static>
     */
    public static function atAGlanceFor(array $memberIds, CarbonImmutable $today): Collection
    {
        $day = $today->toDateString();

        return static::query()
            ->selectRaw('DISTINCT ON (member_id) *')
            ->whereIn('member_id', $memberIds)
            ->notCancelled()
            ->orderBy('member_id')
            ->orderByRaw('CASE WHEN starts_on <= ? AND ends_on >= ? THEN 0 WHEN starts_on > ? THEN 1 ELSE 2 END', [$day, $day, $day])
            ->orderByRaw('CASE WHEN starts_on > ? THEN starts_on END', [$day])
            ->orderByDesc('ends_on')
            ->with(['plan', 'branches', 'freezes'])
            ->get()
            ->keyBy('member_id');
    }

    /**
     * Pause the membership from the first day through the last. Its end date
     * moves later by the number of frozen days, and so does every membership of
     * the member queued after it.
     */
    public function freeze(CarbonImmutable $firstDay, CarbonImmutable $lastDay, string $reason, Staff $frozenBy): MembershipFreeze
    {
        return DB::transaction(function () use ($firstDay, $lastDay, $reason, $frozenBy): MembershipFreeze {
            $days = (int) $firstDay->diffInDays($lastDay) + 1;

            $this->moveQueuedMemberships($days);
            $this->ends_on = $this->ends_on->addDays($days);
            $this->save();

            $freeze = new MembershipFreeze;
            $freeze->organization()->associate($this->organization_id);
            $freeze->membership()->associate($this);
            $freeze->frozenBy()->associate($frozenBy);
            $freeze->starts_on = $firstDay;
            $freeze->ends_on = $lastDay;
            $freeze->reason = $reason;
            $freeze->save();

            $this->unsetRelation('freezes');

            return $freeze;
        });
    }

    /**
     * End the freeze early because the member is back today. It then ends
     * yesterday, or is removed if it hadn't started yet. The days that are no
     * longer frozen come off this membership's end date, and off every
     * membership of the member queued after it.
     */
    public function endFreeze(MembershipFreeze $freeze, CarbonImmutable $today): void
    {
        DB::transaction(function () use ($freeze, $today): void {
            if ($freeze->starts_on->isBefore($today)) {
                $daysNoLongerFrozen = (int) $today->diffInDays($freeze->ends_on) + 1;
                $freeze->ends_on = $today->subDay();
                $freeze->save();
            } else {
                $daysNoLongerFrozen = $freeze->days();
                $freeze->delete();
            }

            $this->ends_on = $this->ends_on->subDays($daysNoLongerFrozen);
            $this->save();
            $this->moveQueuedMemberships(-$daysNoLongerFrozen);

            $this->unsetRelation('freezes');
        });
    }

    /**
     * How many days the membership has been frozen in total.
     */
    public function freezeDaysUsed(): int
    {
        return (int) $this->freezes->sum(fn (MembershipFreeze $freeze): int => $freeze->days());
    }

    public function isFrozenOn(CarbonImmutable $day): bool
    {
        return $this->freezes->contains(fn (MembershipFreeze $freeze): bool => $freeze->covers($day));
    }

    /**
     * Cancel the membership. It stays on record but no longer counts, so its days
     * are free for another membership. Refunds come with Billing.
     */
    public function cancel(Staff $cancelledBy, string $reason): void
    {
        $this->cancelled_at = CarbonImmutable::now();
        $this->cancelledBy()->associate($cancelledBy);
        $this->cancellation_reason = $reason;
        $this->save();
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

    /**
     * The branches this membership is valid at, copied from its plan when it was
     * sold. Ignored while it is valid at all branches.
     *
     * @return BelongsToMany<Branch, $this>
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class);
    }

    /** @return HasMany<MembershipFreeze, $this> */
    public function freezes(): HasMany
    {
        return $this->hasMany(MembershipFreeze::class)->orderBy('starts_on');
    }

    public function statusOn(CarbonImmutable $day): MembershipStatus
    {
        return match (true) {
            $this->cancelled_at !== null => MembershipStatus::Cancelled,
            $this->starts_on->isAfter($day) => MembershipStatus::Upcoming,
            $this->ends_on->isBefore($day) => MembershipStatus::Expired,
            $this->isFrozenOn($day) => MembershipStatus::Frozen,
            default => MembershipStatus::Active,
        };
    }

    /**
     * Memberships that count: not cancelled.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function notCancelled(Builder $query): void
    {
        $query->whereNull('cancelled_at');
    }

    /**
     * Memberships with at least one day from the first day through the last.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function overlapping(Builder $query, CarbonImmutable $firstDay, CarbonImmutable $lastDay): void
    {
        $query->where('starts_on', '<=', $lastDay->toDateString())
            ->where('ends_on', '>=', $firstDay->toDateString());
    }

    /**
     * Move the member's memberships queued after this one by the given number of
     * days (later when positive), together with their freezes. When moving later
     * the furthest moves first, and when moving earlier the nearest moves first,
     * so the database never sees two memberships, or two freezes, overlap.
     */
    private function moveQueuedMemberships(int $days): void
    {
        $queuedMemberships = static::query()
            ->where('member_id', $this->member_id)
            ->notCancelled()
            ->where('starts_on', '>', $this->ends_on->toDateString())
            ->orderBy('starts_on', $days > 0 ? 'desc' : 'asc')
            ->with('freezes')
            ->get();

        foreach ($queuedMemberships as $membership) {
            $membership->starts_on = $membership->starts_on->addDays($days);
            $membership->ends_on = $membership->ends_on->addDays($days);
            $membership->save();

            foreach ($membership->freezes->sortBy('starts_on', descending: $days > 0) as $freeze) {
                $freeze->starts_on = $freeze->starts_on->addDays($days);
                $freeze->ends_on = $freeze->ends_on->addDays($days);
                $freeze->save();
            }
        }
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
            'freeze_days_allowed' => 'integer',
            'list_price' => AsMoney::class.':list_price_minor,currency',
            'price' => AsMoney::class.':price_minor,currency',
            'cancelled_at' => 'immutable_datetime',
            'valid_at_all_branches' => 'boolean',
        ];
    }
}
