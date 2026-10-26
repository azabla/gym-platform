<?php

declare(strict_types=1);

namespace App\Filament\Staff\Widgets\Concerns;

use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use Carbon\CarbonImmutable;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Reads the dashboard's filters as the API's dashboard request does: the period
 * is today unless chosen, at most a year long, and a staff member's figures
 * cover only the branches they work at.
 */
trait ReadsDashboardFilters
{
    use InteractsWithPageFilters;

    protected function gym(): Organization
    {
        return app(CurrentOrganization::class)->get();
    }

    protected function staff(): Staff
    {
        return app(CurrentStaff::class)->get();
    }

    protected function from(): CarbonImmutable
    {
        return $this->dateFilter('from') ?? $this->gym()->today();
    }

    /**
     * The last day of the period: the first day unless chosen, never before it
     * and never more than a year after it.
     */
    protected function to(): CarbonImmutable
    {
        $from = $this->from();
        $to = $this->dateFilter('to') ?? $from;

        return $to->max($from)->min($from->addDays(365));
    }

    /**
     * The branch chosen, if the staff member works at it, or else the ones they
     * work at. Null means every branch.
     *
     * @return list<int>|null
     */
    protected function branchIds(): ?array
    {
        $branchId = $this->pageFilters['branch_id'] ?? null;
        $branch = is_numeric($branchId) ? Branch::query()->find((int) $branchId) : null;

        if ($branch !== null && $this->staff()->worksAt($branch)) {
            return [$branch->id];
        }

        return $this->staff()->limitedToBranchIds();
    }

    private function dateFilter(string $name): ?CarbonImmutable
    {
        $value = $this->pageFilters[$name] ?? null;

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->startOfDay() : null;
    }
}
