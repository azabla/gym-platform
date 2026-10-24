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

    public function evaluate(Member $member, Branch $branch): AccessDecision
    {
        $today = $member->organization->today();
        $membership = Membership::atAGlanceFor([$member->id], $today)->get($member->id);

        $denial = match (true) {
            ! $branch->isOpenAt(CarbonImmutable::now(), $member->organization->timezone) => CheckInDenial::OutsideHours,
            $membership === null => CheckInDenial::MembershipNone,
            default => $this->denialFor($membership, $membership->statusOn($today), $branch) ?? $this->denialForVisits($membership, $today),
        };

        return new AccessDecision($membership, $denial ?? $this->denialForAnotherCheckIn($member));
    }

    private function denialFor(Membership $membership, MembershipStatus $status, Branch $branch): ?CheckInDenial
    {
        return match (true) {
            $status === MembershipStatus::Upcoming, $status === MembershipStatus::Cancelled => CheckInDenial::MembershipNone,
            $status === MembershipStatus::Expired => CheckInDenial::MembershipExpired,
            $status === MembershipStatus::Frozen => CheckInDenial::MembershipFrozen,
            ! $membership->valid_at_all_branches && ! $membership->branches->contains($branch) => CheckInDenial::BranchNotAllowed,
            default => null,
        };
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

    private function denialForAnotherCheckIn(Member $member): ?CheckInDenial
    {
        $recentlyLetIn = CheckIn::query()
            ->whereBelongsTo($member)
            ->where('result', CheckInResult::Allowed)
            ->where('checked_in_at', '>', CarbonImmutable::now()->subMinutes(self::MINUTES_BEFORE_ANOTHER_CHECK_IN))
            ->exists();

        return $recentlyLetIn ? CheckInDenial::AlreadyCheckedIn : null;
    }
}
