<?php

declare(strict_types=1);

namespace App\Domains\Attendance\DoorList;

use App\Domains\Attendance\Access\AccessEvaluator;
use App\Domains\Attendance\Access\Visits;
use App\Domains\Attendance\Credentials\Gq1;
use App\Domains\Attendance\Models\AccessCredential;
use App\Domains\Members\Photos\MemberPhoto;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * What the staff app needs to let members in while offline, for today and
 * tomorrow (UTC): each active credential's day keys, never its secret, and
 * what its member's membership allows on each of the gym's days in that
 * time, with each branch's hours. The app checks codes and rules the way the
 * server does (ADR 0011), and sends the scans when it is back online. See
 * ADR 0038.
 */
final class DoorList
{
    /** How many UTC days of day keys a list carries. */
    public const UTC_DAYS = 2;

    private const SECONDS_PER_DAY = 86400;

    public function __construct(
        private Visits $visits,
        private MemberPhoto $memberPhoto,
    ) {}

    /**
     * The list for the branches, at the gym, as of the moment.
     *
     * @param  Collection<int, Branch>  $branches
     * @return array<string, mixed>
     */
    public function build(Organization $gym, Collection $branches, CarbonImmutable $now): array
    {
        $firstUtcDay = intdiv($now->getTimestamp(), self::SECONDS_PER_DAY);
        $utcDays = range($firstUtcDay, $firstUtcDay + self::UTC_DAYS - 1);
        $validUntil = CarbonImmutable::createFromTimestampUTC(($firstUtcDay + self::UTC_DAYS) * self::SECONDS_PER_DAY);
        $dates = self::gymDatesBetween($now, $validUntil, $gym->timezone);

        $credentials = AccessCredential::query()
            ->active()
            ->whereHas('member')
            ->with('member')
            ->orderBy('id')
            ->get();

        $memberIds = $credentials->pluck('member_id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();
        $membershipsByDate = [];
        $allMemberships = [];

        foreach ($dates as $date) {
            $membershipsByDate[$date->toDateString()] = Membership::atAGlanceFor($memberIds, $date);

            foreach ($membershipsByDate[$date->toDateString()] as $membership) {
                $allMemberships[$membership->id] = $membership;
            }
        }

        $visitsUsed = $this->visits->usedOn($gym->timezone, ...array_values(array_filter(
            $allMemberships,
            fn (Membership $membership): bool => $membership->visit_limit !== null,
        )));

        return [
            'gym' => ['id' => $gym->id, 'slug' => $gym->slug, 'name' => $gym->name, 'timezone' => $gym->timezone],
            'generated_at' => $now->utc()->toIso8601String(),
            'valid_until' => $validUntil->toIso8601String(),
            'utc_days' => $utcDays,
            'dates' => array_map(fn (CarbonImmutable $date): string => $date->toDateString(), $dates),
            'branches' => $branches->map(fn (Branch $branch): array => [
                'id' => $branch->id,
                'name' => $branch->name,
                'hours' => self::hoursOf($branch, $dates),
            ])->values()->all(),
            'credentials' => $credentials->map(fn (AccessCredential $credential): array => [
                'id' => $credential->publicId(),
                'last_used_step' => $credential->last_used_step === null ? null : (int) $credential->last_used_step,
                'day_keys' => self::dayKeysOf($credential, $utcDays),
                'member' => [
                    'id' => $credential->member->id,
                    'full_name' => $credential->member->full_name,
                    'photo_url' => $this->memberPhoto->urlFor($credential->member),
                ],
                'days' => self::daysOf((int) $credential->member_id, $dates, $membershipsByDate, $visitsUsed),
            ])->values()->all(),
        ];
    }

    /**
     * The gym's dates from the moment until just before the end, in order.
     *
     * @return list<CarbonImmutable>
     */
    private static function gymDatesBetween(CarbonImmutable $from, CarbonImmutable $until, string $timezone): array
    {
        $date = CarbonImmutable::parse($from->setTimezone($timezone)->toDateString());
        $last = CarbonImmutable::parse($until->subSecond()->setTimezone($timezone)->toDateString());
        $dates = [];

        while ($date->lte($last)) {
            $dates[] = $date;
            $date = $date->addDay();
        }

        return $dates;
    }

    /**
     * The branch's hours on each date, null when closed. "00:00"–"24:00" is
     * open all day; closing earlier than opening runs past midnight.
     *
     * @param  list<CarbonImmutable>  $dates
     * @return array<string, array{opens_at: string, closes_at: string}|null>
     */
    private static function hoursOf(Branch $branch, array $dates): array
    {
        $closures = $branch->closures()->overlapping($dates[0]->subDay(), $dates[count($dates) - 1])->get();
        $hours = [];

        foreach ($dates as $date) {
            $window = $branch->windowOn($date, $closures);
            $hours[$date->toDateString()] = $window === null ? null : [
                'opens_at' => substr($window->opensAt, 0, 5),
                'closes_at' => substr($window->closesAt, 0, 5),
            ];
        }

        return $hours;
    }

    /**
     * @param  list<int>  $utcDays
     * @return array<int, string> base64 day keys by UTC day, a JSON object keyed by the day
     */
    private static function dayKeysOf(AccessCredential $credential, array $utcDays): array
    {
        $keys = [];

        foreach ($utcDays as $day) {
            $keys[$day] = base64_encode(Gq1::dayKey($credential->rawSecret(), $day));
        }

        return $keys;
    }

    /**
     * What the member's membership allows on each date.
     *
     * @param  list<CarbonImmutable>  $dates
     * @param  array<string, \Illuminate\Support\Collection<array-key, Membership>>  $membershipsByDate
     * @param  array<int, int>  $visitsUsed
     * @return array<string, array{allowed: bool, reason_code: string|null, membership: array{id: int, plan: string}|null, branch_ids: list<int>|null, visits_left: int|null}>
     */
    private static function daysOf(int $memberId, array $dates, array $membershipsByDate, array $visitsUsed): array
    {
        $days = [];

        foreach ($dates as $date) {
            $membership = $membershipsByDate[$date->toDateString()]->get($memberId);
            $denial = $membership === null ? null : AccessEvaluator::denialForStatus($membership->statusOn($date));

            $days[$date->toDateString()] = [
                'allowed' => $membership !== null && $denial === null,
                'reason_code' => $membership === null ? 'MEMBERSHIP_NONE' : $denial?->value,
                'membership' => $membership === null ? null : ['id' => $membership->id, 'plan' => (string) $membership->plan->name],
                'branch_ids' => $membership === null || $membership->valid_at_all_branches
                    ? null
                    : array_map(fn (mixed $id): int => (int) $id, $membership->branches->modelKeys()),
                'visits_left' => $membership?->visit_limit === null
                    ? null
                    : max(0, (int) $membership->visit_limit - ($visitsUsed[$membership->id] ?? 0)),
            ];
        }

        return $days;
    }
}
