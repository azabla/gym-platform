<?php

declare(strict_types=1);

namespace App\Domains\PlatformBilling;

use App\Domains\Organizations\Models\Organization;
use App\Domains\PlatformBilling\Enums\GymBillStatus;
use App\Domains\PlatformBilling\Models\GymInvoice;
use App\Domains\PlatformBilling\Models\GymSubscription;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Where each of a list of gyms stands with the platform's bill, looked up
 * together for a page of gyms rather than once per gym (ADR 0045).
 */
class GymBills
{
    /**
     * Each gym's subscription, by gym id; a gym that isn't billed is missing.
     *
     * @param  iterable<Organization>  $gyms
     * @return array<int, GymSubscription>
     */
    public function subscriptionsOf(iterable $gyms): array
    {
        $ids = $this->idsOf($gyms);

        if ($ids === []) {
            return [];
        }

        return GymSubscription::query()->acrossOrganizations()
            ->whereIn('organization_id', $ids)
            ->with('plan')
            ->get()
            ->keyBy('organization_id')
            ->all();
    }

    /**
     * Each gym's status, by gym id, in each gym's own today.
     *
     * @param  iterable<Organization>  $gyms
     * @return array<int, GymBillStatus>
     */
    public function statusesOf(iterable $gyms): array
    {
        $subscriptions = $this->subscriptionsOf($gyms);
        $earliestDue = $this->earliestOpenDueDaysOf($gyms);
        $statuses = [];

        foreach ($gyms as $gym) {
            $id = (int) $gym->getKey();
            $subscription = $subscriptions[$id] ?? null;
            $dueOn = $earliestDue[$id] ?? null;
            $statuses[$id] = match (true) {
                $subscription === null && $dueOn === null => GymBillStatus::NotBilled,
                $dueOn !== null && $gym->today()->greaterThan($dueOn) => GymBillStatus::Overdue,
                $dueOn !== null => GymBillStatus::Due,
                $subscription->isOnTrial($gym->today()) => GymBillStatus::Trial,
                default => GymBillStatus::PaidUp,
            };
        }

        return $statuses;
    }

    public function statusOf(Organization $gym): GymBillStatus
    {
        return $this->statusesOf([$gym])[(int) $gym->getKey()];
    }

    /**
     * Limit a query of gyms to those with this status, in each gym's own today.
     *
     * @param  Builder<Organization>  $gyms
     * @return Builder<Organization>
     */
    public function whereStatus(Builder $gyms, GymBillStatus $status): Builder
    {
        $today = '(now() AT TIME ZONE organizations.timezone)::date';
        $subscription = fn (): QueryBuilder => DB::table('gym_subscriptions')->whereColumn('gym_subscriptions.organization_id', 'organizations.id');
        $onTrial = fn (): QueryBuilder => $subscription()->whereRaw("gym_subscriptions.trial_ends_on >= {$today}");
        $open = fn (): QueryBuilder => DB::table('gym_invoices')->whereColumn('gym_invoices.organization_id', 'organizations.id')->whereNull('paid_at')->whereNull('voided_at');
        $overdue = fn (): QueryBuilder => $open()->whereRaw("gym_invoices.due_on < {$today}");

        return match ($status) {
            GymBillStatus::NotBilled => $gyms->whereNotExists($subscription())->whereNotExists($open()),
            GymBillStatus::Overdue => $gyms->whereExists($overdue()),
            GymBillStatus::Due => $gyms->whereExists($open())->whereNotExists($overdue()),
            GymBillStatus::Trial => $gyms->whereExists($onTrial())->whereNotExists($open()),
            GymBillStatus::PaidUp => $gyms->whereExists($subscription())->whereNotExists($onTrial())->whereNotExists($open()),
            GymBillStatus::PausedUnpaid => $gyms->whereRaw('false'),
        };
    }

    /**
     * What each gym owes now: the total of its open invoices, by gym id.
     *
     * @param  iterable<Organization>  $gyms
     * @return array<int, Money>
     */
    public function owedBy(iterable $gyms): array
    {
        $owed = [];
        $rows = GymInvoice::query()->acrossOrganizations()->open()
            ->whereIn('organization_id', $this->idsOf($gyms))
            ->groupBy('organization_id')
            ->selectRaw('organization_id, SUM(total_minor) AS owed')
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            $owed[(int) $row->organization_id] = Money::ofMinor((int) $row->owed);
        }

        return $owed;
    }

    /**
     * The due day of each gym's earliest open invoice, by gym id.
     *
     * @param  iterable<Organization>  $gyms
     * @return array<int, CarbonImmutable>
     */
    private function earliestOpenDueDaysOf(iterable $gyms): array
    {
        $days = [];
        $rows = GymInvoice::query()->acrossOrganizations()->open()
            ->whereIn('organization_id', $this->idsOf($gyms))
            ->groupBy('organization_id')
            ->selectRaw('organization_id, MIN(due_on) AS due_on')
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            $days[(int) $row->organization_id] = CarbonImmutable::parse((string) $row->due_on);
        }

        return $days;
    }

    /**
     * @param  iterable<Organization>  $gyms
     * @return list<int>
     */
    private function idsOf(iterable $gyms): array
    {
        $ids = [];

        foreach ($gyms as $gym) {
            $ids[] = (int) $gym->getKey();
        }

        return $ids;
    }
}
