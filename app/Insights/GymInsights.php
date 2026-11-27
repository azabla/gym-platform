<?php

declare(strict_types=1);

namespace App\Insights;

use App\Domains\Attendance\Access\Visits;
use App\Domains\Attendance\Enums\CheckInResult;
use App\Domains\Attendance\Models\CheckIn;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Enums\PaymentType;
use App\Domains\Billing\Models\Payment;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Money\Currency;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Figures owners look at month by month: revenue by plan and method,
 * renewals and churn, the busiest hours, and members and visit packs. Each
 * covers the last few months up to today, by the gym's dates, at the given
 * branches (every one when null), in a fixed number of grouped queries
 * however many members there are. See ADR 0040.
 */
final class GymInsights
{
    public function __construct(private Visits $visits) {}

    /** How many months an insight may cover. */
    public const MONTHS = [3, 6, 12];

    /** A membership counts as renewed when the next one starts within this many days of its end. */
    public const RENEWAL_DAYS = 14;

    /**
     * The first day of each month covered, oldest first, ending with the
     * gym's current month.
     *
     * @return list<CarbonImmutable>
     */
    public function months(Organization $gym, int $count): array
    {
        $current = $gym->today()->startOfMonth();

        return array_map(fn (int $back): CarbonImmutable => $current->subMonthsNoOverflow($back), range($count - 1, 0));
    }

    /**
     * Money taken each month, net of refunds, voided payments left out, at
     * the payment's branch: in all, by plan and by method.
     *
     * @param  list<int>|null  $branchIds
     * @return list<array{month: string, net: Money, by_plan: array<string, Money>, by_method: array<string, Money>}>
     */
    public function revenue(Organization $gym, int $months, ?array $branchIds): array
    {
        $covered = $this->months($gym, $months);

        $sums = Payment::query()
            ->counted()
            ->takenAtAnyOf($branchIds)
            ->where('paid_on', '>=', $covered[0]->toDateString())
            ->where('paid_on', '<=', $gym->today()->toDateString())
            ->selectRaw("to_char(paid_on, 'YYYY-MM') AS month")
            ->selectRaw('(SELECT plans.name FROM memberships JOIN plans ON plans.id = memberships.plan_id WHERE memberships.id = payments.membership_id) AS plan')
            ->selectRaw('method, type, currency, SUM(amount_minor) AS total_minor')
            ->groupByRaw('1, 2, method, type, currency')
            ->toBase()
            ->get();

        /** @var array<string, array<string, Money>> $byPlan */
        $byPlan = [];
        /** @var array<string, array<string, Money>> $byMethod */
        $byMethod = [];

        foreach ($sums as $sum) {
            $total = Money::ofMinor((int) $sum->total_minor, Currency::from((string) $sum->currency));
            $signed = $sum->type === PaymentType::Refund->value ? $total->negate() : $total;
            $month = (string) $sum->month;
            $plan = $sum->plan === null ? 'No plan' : (string) $sum->plan;
            $method = (string) $sum->method;

            $byPlan[$month][$plan] = (isset($byPlan[$month][$plan]) ? $byPlan[$month][$plan] : Money::zero())->add($signed);
            $byMethod[$month][$method] = (isset($byMethod[$month][$method]) ? $byMethod[$month][$method] : Money::zero())->add($signed);
        }

        return array_map(function (CarbonImmutable $month) use ($byPlan, $byMethod): array {
            $key = $month->format('Y-m');
            $methods = [];

            foreach (PaymentMethod::cases() as $method) {
                $methods[$method->value] = $byMethod[$key][$method->value] ?? Money::zero();
            }

            $plans = $byPlan[$key] ?? [];

            return [
                'month' => $key,
                'net' => array_reduce($plans, fn (Money $net, Money $amount): Money => $net->add($amount), Money::zero()),
                'by_plan' => $plans,
                'by_method' => $methods,
            ];
        }, $covered);
    }

    /**
     * Of the memberships that ended each month (not cancelled), by the
     * member's home branch: renewed (another started within 14 days of the
     * end), lapsed (none did), and waiting (ended under 14 days ago, not
     * renewed yet), with the renewal rate among those decided, in all and
     * by plan.
     *
     * @param  list<int>|null  $branchIds
     * @return list<array{month: string, ended: int, renewed: int, lapsed: int, waiting: int, rate: float|null, by_plan: array<string, array{renewed: int, lapsed: int, waiting: int}>}>
     */
    public function renewals(Organization $gym, int $months, ?array $branchIds): array
    {
        $covered = $this->months($gym, $months);
        $today = $gym->today();

        $counts = Membership::query()
            ->notCancelled()
            ->whereIn('member_id', $this->membersAt($branchIds)->select('id'))
            ->where('ends_on', '>=', $covered[0]->toDateString())
            ->where('ends_on', '<', $today->toDateString())
            ->selectRaw("to_char(ends_on, 'YYYY-MM') AS month")
            ->selectRaw('(SELECT name FROM plans WHERE plans.id = memberships.plan_id) AS plan')
            ->selectRaw('EXISTS (SELECT 1 FROM memberships AS later WHERE later.member_id = memberships.member_id AND later.cancelled_at IS NULL AND later.starts_on > memberships.ends_on AND later.starts_on <= memberships.ends_on + ?::int) AS renewed', [self::RENEWAL_DAYS])
            ->selectRaw('memberships.ends_on > ?::date AS recent', [$today->subDays(self::RENEWAL_DAYS)->toDateString()])
            ->selectRaw('COUNT(*) AS total')
            ->groupByRaw('1, 2, 3, 4')
            ->toBase()
            ->get();

        /** @var array<string, array<string, array{renewed: int, lapsed: int, waiting: int}>> $byPlan */
        $byPlan = [];

        foreach ($counts as $count) {
            $outcome = match (true) {
                (bool) $count->renewed => 'renewed',
                (bool) $count->recent => 'waiting',
                default => 'lapsed',
            };
            $month = (string) $count->month;
            $plan = (string) $count->plan;
            $tally = $byPlan[$month][$plan] ?? ['renewed' => 0, 'lapsed' => 0, 'waiting' => 0];
            $tally[$outcome] += (int) $count->total;
            $byPlan[$month][$plan] = $tally;
        }

        return array_map(function (CarbonImmutable $month) use ($byPlan): array {
            $plans = $byPlan[$month->format('Y-m')] ?? [];
            $renewed = array_sum(array_column($plans, 'renewed'));
            $lapsed = array_sum(array_column($plans, 'lapsed'));
            $waiting = array_sum(array_column($plans, 'waiting'));

            return [
                'month' => $month->format('Y-m'),
                'ended' => $renewed + $lapsed + $waiting,
                'renewed' => $renewed,
                'lapsed' => $lapsed,
                'waiting' => $waiting,
                'rate' => $renewed + $lapsed === 0 ? null : round($renewed / ($renewed + $lapsed), 3),
                'by_plan' => $plans,
            ];
        }, $covered);
    }

    /**
     * Check-ins that let members in, by weekday (1 is Monday) and hour, where
     * the gym is, over the months covered, at the branches given: the
     * busiest hours.
     *
     * @param  list<int>|null  $branchIds
     * @return array{grid: array<int, list<int>>, total: int}
     */
    public function busyHours(Organization $gym, int $months, ?array $branchIds): array
    {
        $since = CarbonImmutable::parse($this->months($gym, $months)[0]->toDateString(), $gym->timezone)->utc();

        $counts = CheckIn::query()
            ->madeAtAnyOf($branchIds)
            ->where('result', CheckInResult::Allowed)
            ->where('checked_in_at', '>=', $since)
            ->selectRaw('EXTRACT(ISODOW FROM checked_in_at AT TIME ZONE ?)::int AS weekday', [$gym->timezone])
            ->selectRaw('EXTRACT(HOUR FROM checked_in_at AT TIME ZONE ?)::int AS hour', [$gym->timezone])
            ->selectRaw('COUNT(*) AS total')
            ->groupByRaw('1, 2')
            ->toBase()
            ->get();

        $grid = [];

        foreach (range(1, 7) as $weekday) {
            $grid[$weekday] = array_fill(0, 24, 0);
        }

        foreach ($counts as $count) {
            $grid[(int) $count->weekday][(int) $count->hour] = (int) $count->total;
        }

        return ['grid' => $grid, 'total' => array_sum(array_map('array_sum', $grid))];
    }

    /**
     * For each month: members with a valid membership at its end (today, for
     * this month), new members registered in it, and the visit packs that
     * ended in it: how many, the visits bought and used, and how many ended
     * with visits left. By home branch.
     *
     * @param  list<int>|null  $branchIds
     * @return list<array{month: string, active: int, new: int, packs_ended: int, visits_bought: int, visits_used: int, packs_with_visits_left: int}>
     */
    public function members(Organization $gym, int $months, ?array $branchIds): array
    {
        $covered = $this->months($gym, $months);
        $today = $gym->today();

        $newByMonth = $this->membersAt($branchIds)
            ->where('created_at', '>=', CarbonImmutable::parse($covered[0]->toDateString(), $gym->timezone)->utc())
            ->selectRaw("to_char(created_at AT TIME ZONE ?, 'YYYY-MM') AS month, COUNT(*) AS total", [$gym->timezone])
            ->groupByRaw('1')
            ->toBase()
            ->pluck('total', 'month');

        $packs = Membership::query()
            ->notCancelled()
            ->whereNotNull('visit_limit')
            ->whereIn('member_id', $this->membersAt($branchIds)->select('id'))
            ->where('ends_on', '>=', $covered[0]->toDateString())
            ->where('ends_on', '<', $today->toDateString())
            ->get();
        $used = $this->visits->usedOn($gym->timezone, ...$packs->all());

        return array_map(function (CarbonImmutable $month) use ($branchIds, $today, $newByMonth, $packs, $used): array {
            $key = $month->format('Y-m');
            $endOfMonth = $month->endOfMonth()->startOfDay();
            $day = $endOfMonth->gt($today) ? $today : $endOfMonth;
            $ended = $packs->filter(fn (Membership $pack): bool => $pack->ends_on->format('Y-m') === $key);

            return [
                'month' => $key,
                'active' => Membership::query()
                    ->notCancelled()
                    ->whereIn('member_id', $this->membersAt($branchIds)->select('id'))
                    ->where('starts_on', '<=', $day->toDateString())
                    ->where('ends_on', '>=', $day->toDateString())
                    ->distinct()
                    ->count('member_id'),
                'new' => (int) ($newByMonth[$key] ?? 0),
                'packs_ended' => $ended->count(),
                'visits_bought' => (int) $ended->sum('visit_limit'),
                'visits_used' => (int) $ended->sum(fn (Membership $pack): int => min((int) $pack->visit_limit, $used[$pack->id] ?? 0)),
                'packs_with_visits_left' => $ended->filter(fn (Membership $pack): bool => ($used[$pack->id] ?? 0) < (int) $pack->visit_limit)->count(),
            ];
        }, $covered);
    }

    /**
     * The gym's members, archived ones too (they were members then), at the
     * branches given (by home branch).
     *
     * @param  list<int>|null  $branchIds
     * @return Builder<Member>
     */
    private function membersAt(?array $branchIds): Builder
    {
        return Member::query()->withTrashed()->homeAtAnyOf($branchIds);
    }
}
