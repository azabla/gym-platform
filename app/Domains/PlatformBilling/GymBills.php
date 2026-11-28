<?php

declare(strict_types=1);

namespace App\Domains\PlatformBilling;

use App\Domains\Organizations\Models\Organization;
use App\Domains\PlatformBilling\Enums\GymBillStatus;
use App\Domains\PlatformBilling\Models\GymSubscription;
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
        $statuses = [];

        foreach ($gyms as $gym) {
            $subscription = $subscriptions[(int) $gym->getKey()] ?? null;
            $statuses[(int) $gym->getKey()] = match (true) {
                $subscription === null => GymBillStatus::NotBilled,
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
        $subscription = fn (): QueryBuilder => DB::table('gym_subscriptions')->whereColumn('gym_subscriptions.organization_id', 'organizations.id');
        $onTrial = fn (QueryBuilder $query): QueryBuilder => $query->whereRaw('gym_subscriptions.trial_ends_on >= (now() AT TIME ZONE organizations.timezone)::date');

        return match ($status) {
            GymBillStatus::NotBilled => $gyms->whereNotExists($subscription()),
            GymBillStatus::Trial => $gyms->whereExists($onTrial($subscription())),
            GymBillStatus::PaidUp => $gyms->whereExists($subscription())->whereNotExists($onTrial($subscription())),
            default => $gyms->whereRaw('false'),
        };
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
