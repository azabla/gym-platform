<?php

declare(strict_types=1);

namespace App\Dashboards;

use App\Domains\Attendance\Access\Visits;
use App\Domains\Attendance\Enums\CheckInResult;
use App\Domains\Attendance\Models\CheckIn;
use App\Domains\Billing\Enums\PaymentType;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The gym at a glance for a period: money taken and owed, check-ins, members,
 * and memberships about to run out. It reads from several domains, so it lives
 * outside them; the API and the staff panel both draw from it, so their figures
 * agree. Branch ids limit it to those branches, and null means every branch.
 * See ADR 0014 and ADR 0017.
 */
final class StaffDashboard
{
    /**
     * How many days ahead the expiring list looks, today included.
     */
    private const EXPIRING_DAYS = 7;

    public function __construct(private Visits $visits) {}

    /**
     * Every section, leaving out the money ones for staff who don't see money.
     *
     * @param  list<int>|null  $branchIds
     * @return array<string, mixed>
     */
    public function sections(Organization $gym, CarbonImmutable $from, CarbonImmutable $to, ?array $branchIds, bool $seesMoney): array
    {
        return array_filter([
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'money' => $seesMoney ? $this->money($from, $to, $branchIds) : null,
            'owed' => $seesMoney ? $this->owed($branchIds) : null,
            'check_ins' => $this->checkIns($gym, $from, $to, $branchIds),
            'members' => $this->members($gym, $from, $to, $branchIds),
            'expiring' => $this->expiring($gym, $branchIds, $seesMoney),
            'trends' => $this->trends($gym, $from, $to, $branchIds, $seesMoney),
        ], fn (mixed $section): bool => $section !== null);
    }

    /**
     * The period day by day, for charts: what was taken each day (for staff
     * who see money), check-ins each day where the gym is, and at which hours
     * of the day members came in. Every day appears, with zeros on quiet
     * ones. See ADR 0024.
     *
     * @param  list<int>|null  $branchIds
     * @return array{takings_by_day?: list<array{date: string, net: Money}>, check_ins_by_day: list<array{date: string, allowed: int, denied: int}>, check_ins_by_hour: list<array{hour: int, allowed: int}>}
     */
    public function trends(Organization $gym, CarbonImmutable $from, CarbonImmutable $to, ?array $branchIds, bool $seesMoney): array
    {
        $days = [];

        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $days[] = $day->toDateString();
        }

        $trends = [
            'check_ins_by_day' => $this->checkInsByDay($gym, $from, $to, $branchIds, $days),
            'check_ins_by_hour' => $this->checkInsByHour($gym, $from, $to, $branchIds),
        ];

        return $seesMoney ? ['takings_by_day' => $this->takingsByDay($from, $to, $branchIds, $days), ...$trends] : $trends;
    }

    /**
     * What was taken in the period, as the payments list adds it up, and the
     * open payment links still waiting to be paid.
     *
     * @param  list<int>|null  $branchIds
     * @return array<string, mixed>
     */
    public function money(CarbonImmutable $from, CarbonImmutable $to, ?array $branchIds): array
    {
        $payments = Payment::query()
            ->where('paid_on', '>=', $from->toDateString())
            ->where('paid_on', '<=', $to->toDateString())
            ->when($branchIds !== null, fn ($query) => $query->whereIn('branch_id', $branchIds ?? []));

        $openLinks = PaymentLink::query()
            ->pending()
            ->when($branchIds !== null, fn ($query) => $query->whereIn('branch_id', $branchIds ?? []));

        return [
            ...Payment::totalsOf($payments),
            'open_payment_links' => [
                'count' => (clone $openLinks)->count(),
                'amount' => Money::ofMinor((int) (clone $openLinks)->sum('amount_minor')),
            ],
        ];
    }

    /**
     * What members still owe across their memberships, and how many owe.
     *
     * @param  list<int>|null  $branchIds
     * @return array{total: Money, members: int}
     */
    public function owed(?array $branchIds): array
    {
        $memberIds = array_map('intval', $this->membersAt($branchIds)->pluck('id')->all());
        $owing = array_filter(Payment::balancesDueOf($memberIds), fn (Money $balanceDue): bool => $balanceDue->isPositive());

        return [
            'total' => array_reduce($owing, fn (Money $total, Money $balanceDue): Money => $total->add($balanceDue), Money::zero()),
            'members' => count($owing),
        ];
    }

    /**
     * Check-ins in the period, where the gym is, allowed and denied, and the
     * denials by reason.
     *
     * @param  list<int>|null  $branchIds
     * @return array{allowed: int, denied: int, denied_by_reason: object}
     */
    public function checkIns(Organization $gym, CarbonImmutable $from, CarbonImmutable $to, ?array $branchIds): array
    {
        $counts = CheckIn::query()
            ->where('checked_in_at', '>=', $this->startOf($gym, $from))
            ->where('checked_in_at', '<', $this->startOf($gym, $to->addDay()))
            ->when($branchIds !== null, fn ($query) => $query->whereIn('branch_id', $branchIds ?? []))
            ->groupBy('result', 'reason_code')
            ->selectRaw('result, reason_code, COUNT(*) AS total')
            ->toBase()
            ->get();

        $allowed = 0;
        $deniedByReason = [];

        foreach ($counts as $count) {
            if ($count->result === CheckInResult::Allowed->value) {
                $allowed += (int) $count->total;
            } else {
                $deniedByReason[(string) $count->reason_code] = (int) $count->total;
            }
        }

        arsort($deniedByReason);

        return [
            'allowed' => $allowed,
            'denied' => array_sum($deniedByReason),
            'denied_by_reason' => (object) $deniedByReason,
        ];
    }

    /**
     * Members with a membership valid today, members registered in the
     * period, and memberships sold in the period, at the desk and in the app.
     *
     * @param  list<int>|null  $branchIds
     * @return array{active: int, new: int, memberships_sold: array{at_the_desk: int, in_the_app: int}}
     */
    public function members(Organization $gym, CarbonImmutable $from, CarbonImmutable $to, ?array $branchIds): array
    {
        $today = $gym->today()->toDateString();
        $since = $this->startOf($gym, $from);
        $until = $this->startOf($gym, $to->addDay());

        $sold = Membership::query()
            ->where('created_at', '>=', $since)
            ->where('created_at', '<', $until)
            ->whereIn('member_id', $this->membersAt($branchIds)->select('id'));

        return [
            'active' => Membership::query()
                ->notCancelled()
                ->where('starts_on', '<=', $today)
                ->where('ends_on', '>=', $today)
                ->whereIn('member_id', $this->membersAt($branchIds)->select('id'))
                ->distinct()
                ->count('member_id'),
            'new' => $this->membersAt($branchIds)->where('created_at', '>=', $since)->where('created_at', '<', $until)->count(),
            'memberships_sold' => [
                'at_the_desk' => (clone $sold)->where('sold_in_app', false)->count(),
                'in_the_app' => (clone $sold)->where('sold_in_app', true)->count(),
            ],
        ];
    }

    /**
     * Memberships ending in the next week, and visit packs valid today with
     * at most one visit left, of members still with the gym, with nothing
     * sold to follow them: the members to call. Soonest end first.
     *
     * @param  list<int>|null  $branchIds
     * @return list<array<string, mixed>>
     */
    public function expiring(Organization $gym, ?array $branchIds, bool $seesMoney): array
    {
        $today = $gym->today();
        $endOfWindow = $today->addDays(self::EXPIRING_DAYS)->toDateString();
        $toCall = fn () => Membership::query()
            ->notCancelled()
            ->where('ends_on', '>=', $today->toDateString())
            ->whereIn('member_id', $this->membersAt($branchIds)->select('id'))
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('memberships as later')
                ->whereColumn('later.member_id', 'memberships.member_id')
                ->whereColumn('later.starts_on', '>', 'memberships.ends_on')
                ->whereNull('later.cancelled_at'))
            ->with(['member', 'plan']);

        $endingSoon = $toCall()->where('ends_on', '<', $endOfWindow)->get();
        $packs = $toCall()->where('ends_on', '>=', $endOfWindow)->where('starts_on', '<=', $today->toDateString())->whereNotNull('visit_limit')->get();
        $visitsUsed = $this->visits->usedOn($gym->timezone, ...$endingSoon->all(), ...$packs->all());
        $visitsLeft = fn (Membership $membership): ?int => $membership->visit_limit === null
            ? null
            : max(0, $membership->visit_limit - $visitsUsed[$membership->id]);

        $memberships = $endingSoon
            ->concat($packs->filter(fn (Membership $membership): bool => $visitsLeft($membership) <= 1))
            ->sortBy([['ends_on', 'asc'], ['id', 'asc']])
            ->values();

        $balances = $seesMoney ? Payment::balancesOf($memberships->all()) : [];

        return $memberships->map(fn (Membership $membership): array => array_filter([
            'membership_id' => $membership->id,
            'member' => [
                'id' => $membership->member->id,
                'full_name' => $membership->member->full_name,
                'phone' => $membership->member->phone,
            ],
            'plan' => ['id' => $membership->plan->id, 'name' => $membership->plan->name],
            'ends_on' => $membership->ends_on->toDateString(),
            'visits_remaining' => $visitsLeft($membership),
            'balance_due' => $seesMoney ? $balances[$membership->id]->balanceDue : null,
        ], fn (mixed $value): bool => $value !== null))->values()->all();
    }

    /**
     * The moment the day begins where the gym is, in UTC.
     */
    /**
     * What was taken each day, payments less refunds; voided ones don't count.
     *
     * @param  list<int>|null  $branchIds
     * @param  list<string>  $days
     * @return list<array{date: string, net: Money}>
     */
    private function takingsByDay(CarbonImmutable $from, CarbonImmutable $to, ?array $branchIds, array $days): array
    {
        $sums = Payment::query()
            ->counted()
            ->where('paid_on', '>=', $from->toDateString())
            ->where('paid_on', '<=', $to->toDateString())
            ->when($branchIds !== null, fn ($query) => $query->whereIn('branch_id', $branchIds ?? []))
            ->groupBy('paid_on', 'type')
            ->selectRaw('paid_on, type, SUM(amount_minor) AS total_minor')
            ->toBase()
            ->get();

        $netMinor = array_fill_keys($days, 0);

        foreach ($sums as $sum) {
            $day = substr((string) $sum->paid_on, 0, 10);
            $netMinor[$day] = ($netMinor[$day] ?? 0) + ($sum->type === PaymentType::Refund->value ? -1 : 1) * (int) $sum->total_minor;
        }

        return array_map(fn (string $day): array => ['date' => $day, 'net' => Money::ofMinor($netMinor[$day])], $days);
    }

    /**
     * Check-ins each day where the gym is, let in and turned away.
     *
     * @param  list<int>|null  $branchIds
     * @param  list<string>  $days
     * @return list<array{date: string, allowed: int, denied: int}>
     */
    private function checkInsByDay(Organization $gym, CarbonImmutable $from, CarbonImmutable $to, ?array $branchIds, array $days): array
    {
        $counts = $this->checkInsIn($gym, $from, $to, $branchIds)
            ->selectRaw('(checked_in_at AT TIME ZONE ?)::date AS day, result, COUNT(*) AS total', [$gym->timezone])
            ->groupByRaw('1, 2')
            ->toBase()
            ->get();

        $byDay = array_fill_keys($days, ['allowed' => 0, 'denied' => 0]);

        foreach ($counts as $count) {
            $key = $count->result === CheckInResult::Allowed->value ? 'allowed' : 'denied';
            $byDay[(string) $count->day][$key] = (int) $count->total;
        }

        return array_map(fn (string $day): array => ['date' => $day, ...$byDay[$day]], $days);
    }

    /**
     * At which hours of the day, where the gym is, members were let in.
     *
     * @param  list<int>|null  $branchIds
     * @return list<array{hour: int, allowed: int}>
     */
    private function checkInsByHour(Organization $gym, CarbonImmutable $from, CarbonImmutable $to, ?array $branchIds): array
    {
        $counts = $this->checkInsIn($gym, $from, $to, $branchIds)
            ->where('result', CheckInResult::Allowed)
            ->selectRaw('EXTRACT(HOUR FROM checked_in_at AT TIME ZONE ?)::int AS hour, COUNT(*) AS total', [$gym->timezone])
            ->groupByRaw('1')
            ->toBase()
            ->pluck('total', 'hour');

        return array_map(fn (int $hour): array => ['hour' => $hour, 'allowed' => (int) ($counts[$hour] ?? 0)], range(0, 23));
    }

    /**
     * The check-ins in the period, where the gym is, at the branches.
     *
     * @param  list<int>|null  $branchIds
     * @return Builder<CheckIn>
     */
    private function checkInsIn(Organization $gym, CarbonImmutable $from, CarbonImmutable $to, ?array $branchIds): Builder
    {
        return CheckIn::query()
            ->where('checked_in_at', '>=', $this->startOf($gym, $from))
            ->where('checked_in_at', '<', $this->startOf($gym, $to->addDay()))
            ->when($branchIds !== null, fn ($query) => $query->whereIn('branch_id', $branchIds ?? []));
    }

    private function startOf(Organization $gym, CarbonImmutable $day): CarbonImmutable
    {
        return CarbonImmutable::parse($day->toDateString(), $gym->timezone)->utc();
    }

    /**
     * The gym's members still with it, at the branches given (by home branch).
     *
     * @param  list<int>|null  $branchIds
     * @return Builder<Member>
     */
    private function membersAt(?array $branchIds): Builder
    {
        return Member::query()->when($branchIds !== null, fn ($query) => $query->whereIn('home_branch_id', $branchIds ?? []));
    }
}
