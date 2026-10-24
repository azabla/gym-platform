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
 * The membership rules at the door, the same for a QR scan and a desk
 * check-in: a membership valid today, not frozen, valid at this branch, and
 * no other check-in in the last 5 minutes. Money owed doesn't stop anyone;
 * the desk sees it and can ask. See ADR 0011.
 */
final class AccessEvaluator
{
    public const MINUTES_BEFORE_ANOTHER_CHECK_IN = 5;

    public function evaluate(Member $member, Branch $branch): AccessDecision
    {
        $today = $member->organization->today();
        $membership = Membership::atAGlanceFor([$member->id], $today)->get($member->id);

        $denial = $membership === null
            ? CheckInDenial::MembershipNone
            : $this->denialFor($membership, $membership->statusOn($today), $branch);

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
