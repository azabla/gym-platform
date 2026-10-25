<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Domains\Attendance\Enums\CheckInResult;
use App\Domains\Attendance\Models\CheckIn;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Shared\Money\Money;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Dashboard\ShowDashboardRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    /**
     * How many days ahead the expiring list looks, today included.
     */
    private const EXPIRING_DAYS = 7;

    /**
     * The gym at a glance for the period (today by default): money taken and
     * owed, check-ins, members, and memberships about to run out. Money is
     * shown only to the roles that see it, and staff who work at some
     * branches see only those. See ADR 0014.
     */
    public function __invoke(ShowDashboardRequest $request, CurrentOrganization $currentOrganization): JsonResponse
    {
        $from = $request->from();
        $to = $request->to();
        $branchIds = $request->branchIds();
        $timezone = $currentOrganization->get()->timezone;
        $seesMoney = $request->seesMoney();

        return response()->json(['data' => array_filter([
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'money' => $seesMoney ? $this->money($from, $to, $branchIds) : null,
            'owed' => $seesMoney ? $this->owed($branchIds) : null,
            'check_ins' => $this->checkIns(
                CarbonImmutable::parse($from->toDateString(), $timezone)->utc(),
                CarbonImmutable::parse($to->addDay()->toDateString(), $timezone)->utc(),
                $branchIds,
            ),
            'members' => $this->members($request->today(), $from, $to, $timezone, $branchIds),
            'expiring' => $this->expiring($request->today(), $branchIds, $seesMoney),
        ], fn (mixed $section): bool => $section !== null)]);
    }

    /**
     * What was taken in the period, as the payments list adds it up, and the
     * open payment links still waiting to be paid.
     *
     * @param  list<int>|null  $branchIds
     * @return array<string, mixed>
     */
    private function money(CarbonImmutable $from, CarbonImmutable $to, ?array $branchIds): array
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
    private function owed(?array $branchIds): array
    {
        $memberIds = array_map('intval', $this->membersAt($branchIds)->pluck('id')->all());
        $owing = array_filter(Payment::balancesDueOf($memberIds), fn (Money $balanceDue): bool => $balanceDue->isPositive());

        return [
            'total' => array_reduce($owing, fn (Money $total, Money $balanceDue): Money => $total->add($balanceDue), Money::zero()),
            'members' => count($owing),
        ];
    }

    /**
     * Check-ins in the period, allowed and denied, and the denials by reason.
     *
     * @param  list<int>|null  $branchIds
     * @return array<string, mixed>
     */
    private function checkIns(CarbonImmutable $from, CarbonImmutable $until, ?array $branchIds): array
    {
        $counts = CheckIn::query()
            ->where('checked_in_at', '>=', $from)
            ->where('checked_in_at', '<', $until)
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
     * @return array<string, mixed>
     */
    private function members(CarbonImmutable $today, CarbonImmutable $from, CarbonImmutable $to, string $timezone, ?array $branchIds): array
    {
        $since = CarbonImmutable::parse($from->toDateString(), $timezone)->utc();
        $until = CarbonImmutable::parse($to->addDay()->toDateString(), $timezone)->utc();

        $sold = Membership::query()
            ->where('created_at', '>=', $since)
            ->where('created_at', '<', $until)
            ->whereIn('member_id', $this->membersAt($branchIds)->select('id'));

        return [
            'active' => Membership::query()
                ->notCancelled()
                ->where('starts_on', '<=', $today->toDateString())
                ->where('ends_on', '>=', $today->toDateString())
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
     * Memberships ending in the next week, of members still with the gym,
     * with nothing sold to follow them: the members to call. Soonest first.
     *
     * @param  list<int>|null  $branchIds
     * @return list<array<string, mixed>>
     */
    private function expiring(CarbonImmutable $today, ?array $branchIds, bool $seesMoney): array
    {
        $memberships = Membership::query()
            ->notCancelled()
            ->where('ends_on', '>=', $today->toDateString())
            ->where('ends_on', '<', $today->addDays(self::EXPIRING_DAYS)->toDateString())
            ->whereIn('member_id', $this->membersAt($branchIds)->select('id'))
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('memberships as later')
                ->whereColumn('later.member_id', 'memberships.member_id')
                ->whereColumn('later.starts_on', '>', 'memberships.ends_on')
                ->whereNull('later.cancelled_at'))
            ->with(['member', 'plan'])
            ->orderBy('ends_on')
            ->orderBy('id')
            ->get();

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
            'balance_due' => $seesMoney ? $balances[$membership->id]->balanceDue : null,
        ], fn (mixed $value): bool => $value !== null))->values()->all();
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
