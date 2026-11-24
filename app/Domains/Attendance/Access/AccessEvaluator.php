<?php

declare(strict_types=1);

namespace App\Domains\Attendance\Access;

use App\Domains\Attendance\Enums\CheckInDenial;
use App\Domains\Attendance\Enums\CheckInResult;
use App\Domains\Attendance\Models\CheckIn;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Enums\MembershipStatus;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Models\Branch;
use Carbon\CarbonImmutable;

/**
 * The rules at the door, the same for a QR scan and a desk check-in: the
 * branch open now, a membership valid today, not frozen, valid at this branch, with
 * a visit left when it is a visit pack, and no other check-in in the last 5
 * minutes. Money owed doesn't stop anyone; the desk sees it and can ask. See
 * ADR 0011 and ADR 0016.
 */
final class AccessEvaluator
{
    public const MINUTES_BEFORE_ANOTHER_CHECK_IN = 5;

    public function __construct(private Visits $visits) {}

    /**
     * Decide for the member at the branch, now, or at an earlier moment for a
     * scan the staff app made offline (ADR 0038): the day, the hours, the
     * membership and the 5-minute rule all follow that moment.
     */
    public function evaluate(Member $member, Branch $branch, ?CarbonImmutable $at = null): AccessDecision
    {
        $at ??= CarbonImmutable::now();
        $timezone = $member->organization->timezone;
        $day = CarbonImmutable::parse($at->setTimezone($timezone)->toDateString());
        $membership = Membership::atAGlanceFor([$member->id], $day)->get($member->id);

        $denial = match (true) {
            ! $branch->isOpenAt($at, $timezone) => CheckInDenial::OutsideHours,
            $membership === null => CheckInDenial::MembershipNone,
            default => self::denialForStatus($membership->statusOn($day))
                ?? self::denialForBranch($membership, $branch)
                ?? $this->denialForVisits($membership, $day),
        };

        return new AccessDecision($membership, $denial ?? $this->denialForAnotherCheckIn($member, $at));
    }

    /**
     * Why a membership in this state lets nobody in, or null when it does.
     * The door list uses it for each day it covers.
     */
    public static function denialForStatus(MembershipStatus $status): ?CheckInDenial
    {
        return match ($status) {
            MembershipStatus::Upcoming, MembershipStatus::Cancelled => CheckInDenial::MembershipNone,
            MembershipStatus::Expired => CheckInDenial::MembershipExpired,
            MembershipStatus::Frozen => CheckInDenial::MembershipFrozen,
            default => null,
        };
    }

    private static function denialForBranch(Membership $membership, Branch $branch): ?CheckInDenial
    {
        return ! $membership->valid_at_all_branches && ! $membership->branches->contains($branch)
            ? CheckInDenial::BranchNotAllowed
            : null;
    }

    /**
     * A visit pack with every visit used lets the member in only on a day they
     * already came in, which uses no new visit.
     */
    private function denialForVisits(Membership $membership, CarbonImmutable $today): ?CheckInDenial
    {
        if ($membership->visit_limit === null) {
            return null;
        }

        $timezone = $membership->organization->timezone;
        $used = $this->visits->usedOn($timezone, $membership)[$membership->id];

        if ($used < $membership->visit_limit || $this->visits->cameInOn($membership, $today, $timezone)) {
            return null;
        }

        return CheckInDenial::VisitsUsedUp;
    }

    private function denialForAnotherCheckIn(Member $member, CarbonImmutable $at): ?CheckInDenial
    {
        $recentlyLetIn = CheckIn::query()
            ->whereBelongsTo($member)
            ->where('result', CheckInResult::Allowed)
            ->where('checked_in_at', '>', $at->subMinutes(self::MINUTES_BEFORE_ANOTHER_CHECK_IN))
            ->where('checked_in_at', '<=', $at)
            ->exists();

        return $recentlyLetIn ? CheckInDenial::AlreadyCheckedIn : null;
    }
}
