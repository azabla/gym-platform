<?php

declare(strict_types=1);

namespace App\Domains\PlatformBilling;

use App\Domains\Organizations\Enums\SuspensionCause;
use App\Domains\Organizations\Models\GymSuspension;
use App\Domains\Organizations\Models\Organization;
use App\Domains\PlatformBilling\Enums\GymBillStatus;
use App\Domains\PlatformBilling\Models\GymInvoice;
use App\Domains\PlatformBilling\Models\GymInvoicePayment;
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
        $pausedForUnpaidBills = $this->pausedForUnpaidBills($gyms);
        $statuses = [];

        foreach ($gyms as $gym) {
            $id = (int) $gym->getKey();
            $subscription = $subscriptions[$id] ?? null;
            $dueOn = $earliestDue[$id] ?? null;
            $statuses[$id] = match (true) {
                in_array($id, $pausedForUnpaidBills, true) => GymBillStatus::PausedUnpaid,
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
     * SQL for each gym's today, from the app's clock rather than the
     * database's, so it agrees with Organization::today(). The moment is
     * written by the app, never taken from input.
     */
    public static function gymTodaySql(string $timezoneColumn = 'organizations.timezone'): string
    {
        return sprintf("(TIMESTAMPTZ '%s' AT TIME ZONE %s)::date", CarbonImmutable::now()->utc()->format('Y-m-d H:i:s').'+00', $timezoneColumn);
    }

    /**
     * Limit a query of gyms to those with this status, in each gym's own today.
     *
     * @param  Builder<Organization>  $gyms
     * @return Builder<Organization>
     */
    public function whereStatus(Builder $gyms, GymBillStatus $status): Builder
    {
        $today = self::gymTodaySql();
        $subscription = fn (): QueryBuilder => DB::table('gym_subscriptions')->whereColumn('gym_subscriptions.organization_id', 'organizations.id');
        $onTrial = fn (): QueryBuilder => $subscription()->whereRaw("gym_subscriptions.trial_ends_on >= {$today}");
        $open = fn (): QueryBuilder => DB::table('gym_invoices')->whereColumn('gym_invoices.organization_id', 'organizations.id')->whereNull('paid_at')->whereNull('voided_at');
        $overdue = fn (): QueryBuilder => $open()->whereRaw("gym_invoices.due_on < {$today}");
        $pausedUnpaid = fn (): QueryBuilder => DB::table('gym_suspensions')->whereColumn('gym_suspensions.organization_id', 'organizations.id')
            ->whereNull('resumed_at')
            ->where('cause', SuspensionCause::UnpaidBill->value);

        $gyms = $status === GymBillStatus::PausedUnpaid ? $gyms : $gyms->whereNotExists($pausedUnpaid());

        return match ($status) {
            GymBillStatus::PausedUnpaid => $gyms->whereExists($pausedUnpaid()),
            GymBillStatus::NotBilled => $gyms->whereNotExists($subscription())->whereNotExists($open()),
            GymBillStatus::Overdue => $gyms->whereExists($overdue()),
            GymBillStatus::Due => $gyms->whereExists($open())->whereNotExists($overdue()),
            GymBillStatus::Trial => $gyms->whereExists($onTrial())->whereNotExists($open()),
            GymBillStatus::PaidUp => $gyms->whereExists($subscription())->whereNotExists($onTrial())->whereNotExists($open()),
        };
    }

    /**
     * The platform's own figures, counts and totals only: what gyms owe now,
     * how many are overdue, what was paid this month (in Addis Ababa's
     * month) and how many gyms are on their trial.
     *
     * @return array{owed: Money, overdue_gyms: int, collected_this_month: Money, on_trial: int}
     */
    public function platformFigures(): array
    {
        $today = self::gymTodaySql();
        $month = CarbonImmutable::now('Africa/Addis_Ababa');

        return [
            'owed' => Money::ofMinor((int) GymInvoice::query()->acrossOrganizations()->open()->sum('total_minor')
                - (int) GymInvoicePayment::query()->acrossOrganizations()->whereIn('gym_invoice_id', GymInvoice::query()->acrossOrganizations()->open()->select('id'))->sum('amount_minor')),
            'overdue_gyms' => (int) GymInvoice::query()->acrossOrganizations()->open()
                ->join('organizations', 'organizations.id', '=', 'gym_invoices.organization_id')
                ->whereRaw("gym_invoices.due_on < {$today}")
                ->distinct()
                ->count('gym_invoices.organization_id'),
            'collected_this_month' => Money::ofMinor((int) GymInvoicePayment::query()->acrossOrganizations()
                ->whereBetween('paid_on', [$month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString()])
                ->sum('amount_minor')),
            'on_trial' => GymSubscription::query()->acrossOrganizations()
                ->join('organizations', 'organizations.id', '=', 'gym_subscriptions.organization_id')
                ->whereRaw("gym_subscriptions.trial_ends_on >= {$today}")
                ->count(),
        ];
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
            ->selectRaw('organization_id, SUM(total_minor) - COALESCE(SUM((SELECT SUM(amount_minor) FROM gym_invoice_payments WHERE gym_invoice_payments.gym_invoice_id = gym_invoices.id)), 0) AS owed')
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            $owed[(int) $row->organization_id] = Money::ofMinor((int) $row->owed);
        }

        return $owed;
    }

    /**
     * The ids of the gyms paused, by the platform, for an unpaid bill.
     *
     * @param  iterable<Organization>  $gyms
     * @return list<int>
     */
    private function pausedForUnpaidBills(iterable $gyms): array
    {
        return array_map('intval', GymSuspension::query()->acrossOrganizations()
            ->whereIn('organization_id', $this->idsOf($gyms))
            ->whereNull('resumed_at')
            ->where('cause', SuspensionCause::UnpaidBill)
            ->pluck('organization_id')
            ->all());
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
