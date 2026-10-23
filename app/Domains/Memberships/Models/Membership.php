<?php

declare(strict_types=1);

namespace App\Domains\Memberships\Models;

use App\Casts\AsMoney;
use App\Domains\Members\Models\Member;
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
use Illuminate\Support\Facades\DB;

/**
 * A plan sold to a member for set dates, from starts_on through ends_on. The
 * price and the branches it is valid at are copied from the plan when it is
 * sold. A member has at most one membership on any day (cancelled ones don't
 * count), which the database enforces. See ADR 0006 and ADR 0007.
 *
 * @property Money $price Stored in the price_minor and currency columns.
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
    ];

    /**
     * Sell the plan to the member, starting on the given day. The membership
     * belongs to the member's gym, and its end date, price and branches come
     * from the plan.
     */
    public static function sell(Member $member, Plan $plan, CarbonImmutable $startsOn, Staff $soldBy): self
    {
        return DB::transaction(function () use ($member, $plan, $startsOn, $soldBy): self {
            $membership = new self;
            $membership->organization()->associate($member->organization_id);
            $membership->member()->associate($member);
            $membership->plan()->associate($plan);
            $membership->soldBy()->associate($soldBy);
            $membership->starts_on = $startsOn;
            $membership->ends_on = $plan->endsOnFor($startsOn);
            $membership->price = $plan->price;
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
            ->with(['plan', 'branches'])
            ->get()
            ->keyBy('member_id');
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
            'valid_at_all_branches' => 'boolean',
        ];
    }
}
