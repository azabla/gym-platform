<?php

declare(strict_types=1);

namespace App\Dashboards;

use App\Domains\Attendance\Enums\CheckInResult;
use App\Domains\Attendance\Models\CheckIn;
use App\Domains\Billing\Enums\PaymentType;
use App\Domains\Billing\Models\Payment;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * How each gym is doing, for the platform's admin panel: counts and totals
 * only, never a member's name or a payment (ADR 0042). Read across gyms in
 * a few grouped queries, with "today", "this month" and "the last 7 days"
 * where each gym is.
 *
 * @phpstan-type Figures array{members: int, valid_memberships: int, staff: int, check_ins_last_7_days: int, taken_this_month: Money, last_activity: CarbonImmutable|null}
 */
final class GymFigures
{
    /**
     * The figures of each gym, by its id.
     *
     * @param  iterable<Organization>  $gyms
     * @return array<int, Figures>
     */
    public function of(iterable $gyms): array
    {
        $byTimezone = [];

        foreach ($gyms as $gym) {
            $byTimezone[(string) $gym->timezone][] = (int) $gym->getKey();
        }

        $figures = [];

        foreach ($byTimezone as $timezone => $ids) {
            $figures += $this->forGymsIn($timezone, $ids);
        }

        return $figures;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, Figures>
     */
    private function forGymsIn(string $timezone, array $ids): array
    {
        $today = CarbonImmutable::parse(CarbonImmutable::now($timezone)->toDateString());
        $weekAgo = CarbonImmutable::parse($today->subDays(6)->toDateString(), $timezone)->utc();

        $members = $this->countsBy(Member::query()->acrossOrganizations(), $ids);
        $staff = $this->countsBy(Staff::query()->acrossOrganizations(), $ids);
        $checkIns = $this->countsBy(
            CheckIn::query()->acrossOrganizations()->where('result', CheckInResult::Allowed)->where('checked_in_at', '>=', $weekAgo),
            $ids,
        );
        $validMemberships = $this->countsBy(
            Membership::query()->acrossOrganizations()->notCancelled()
                ->where('starts_on', '<=', $today->toDateString())
                ->where('ends_on', '>=', $today->toDateString()),
            $ids,
            'COUNT(DISTINCT member_id)',
        );
        $taken = $this->countsBy(
            Payment::query()->acrossOrganizations()->counted()
                ->where('paid_on', '>=', $today->startOfMonth()->toDateString())
                ->where('paid_on', '<=', $today->toDateString()),
            $ids,
            "SUM(CASE WHEN type = '".PaymentType::Refund->value."' THEN -amount_minor ELSE amount_minor END)",
        );
        $lastActivity = $this->latest($ids);

        $figures = [];

        foreach ($ids as $id) {
            $figures[$id] = [
                'members' => $members[$id] ?? 0,
                'valid_memberships' => $validMemberships[$id] ?? 0,
                'staff' => $staff[$id] ?? 0,
                'check_ins_last_7_days' => $checkIns[$id] ?? 0,
                'taken_this_month' => Money::ofMinor($taken[$id] ?? 0),
                'last_activity' => $lastActivity[$id] ?? null,
            ];
        }

        return $figures;
    }

    /**
     * One number per gym from the query, by gym id.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function countsBy(Builder $query, array $ids, string $aggregate = 'COUNT(*)'): array
    {
        $rows = $query->whereIn('organization_id', $ids)
            ->groupBy('organization_id')
            ->selectRaw("organization_id, {$aggregate} AS figure")
            ->toBase()
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row->organization_id] = (int) $row->figure;
        }

        return $counts;
    }

    /**
     * When something last happened at each gym: a check-in, a payment or a
     * membership sold.
     *
     * @param  list<int>  $ids
     * @return array<int, CarbonImmutable>
     */
    private function latest(array $ids): array
    {
        $sources = [
            [CheckIn::query()->acrossOrganizations(), 'checked_in_at'],
            [Payment::query()->acrossOrganizations(), 'created_at'],
            [Membership::query()->acrossOrganizations(), 'created_at'],
        ];

        $latest = [];

        foreach ($sources as [$query, $column]) {
            $rows = $query->whereIn('organization_id', $ids)
                ->groupBy('organization_id')
                ->selectRaw("organization_id, MAX({$column}) AS happened_at")
                ->toBase()
                ->get();

            foreach ($rows as $row) {
                $at = CarbonImmutable::parse((string) $row->happened_at);
                $id = (int) $row->organization_id;
                $latest[$id] = isset($latest[$id]) && $latest[$id]->greaterThan($at) ? $latest[$id] : $at;
            }
        }

        return $latest;
    }
}
