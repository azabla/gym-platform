<?php

declare(strict_types=1);

namespace App\Domains\Attendance\Access;

use App\Domains\Attendance\Enums\CheckInResult;
use App\Domains\Attendance\Models\CheckIn;
use App\Domains\Memberships\Models\Membership;
use Carbon\CarbonImmutable;

/**
 * Counts the visits of visit packs: the days, where the gym is, with at least
 * one allowed check-in on the membership. Coming back the same day, at any
 * branch, is the same visit. Worked out from the check-ins each time, never
 * stored, so there is no counter to get wrong. See ADR 0016.
 */
final class Visits
{
    /**
     * The visits used on each of the memberships, keyed by membership id.
     * Memberships without a visit yet are 0.
     *
     * @return array<int, int>
     */
    public function usedOn(string $timezone, Membership ...$memberships): array
    {
        $ids = array_map(fn (Membership $membership): int => $membership->id, $memberships);

        if ($ids === []) {
            return [];
        }

        $counts = CheckIn::query()
            ->whereIn('membership_id', $ids)
            ->where('result', CheckInResult::Allowed)
            ->groupBy('membership_id')
            ->selectRaw('membership_id, count(DISTINCT (checked_in_at AT TIME ZONE ?)::date) AS visits', [$timezone])
            ->pluck('visits', 'membership_id');

        $used = [];

        foreach ($ids as $id) {
            $used[$id] = (int) ($counts[$id] ?? 0);
        }

        return $used;
    }

    /**
     * Whether the allowed check-in is the membership's first of its day, where
     * the gym is: the one that used a visit.
     */
    public function isFirstOfItsDay(CheckIn $checkIn, string $timezone): bool
    {
        $startOfDay = CarbonImmutable::parse($checkIn->checked_in_at->setTimezone($timezone)->toDateString(), $timezone);

        return ! CheckIn::query()
            ->where('membership_id', $checkIn->membership_id)
            ->where('result', CheckInResult::Allowed)
            ->whereKeyNot($checkIn->id)
            ->where('checked_in_at', '>=', $startOfDay->utc())
            ->where('checked_in_at', '<=', $checkIn->checked_in_at)
            ->exists();
    }

    /**
     * Whether the membership already has an allowed check-in on the day, where
     * the gym is, so another one that day uses no visit.
     */
    public function cameInOn(Membership $membership, CarbonImmutable $day, string $timezone): bool
    {
        $startOfDay = CarbonImmutable::parse($day->toDateString(), $timezone);

        return CheckIn::query()
            ->where('membership_id', $membership->id)
            ->where('result', CheckInResult::Allowed)
            ->where('checked_in_at', '>=', $startOfDay->utc())
            ->where('checked_in_at', '<', $startOfDay->addDay()->utc())
            ->exists();
    }
}
