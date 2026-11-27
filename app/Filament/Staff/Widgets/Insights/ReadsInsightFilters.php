<?php

declare(strict_types=1);

namespace App\Filament\Staff\Widgets\Insights;

use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Filament\Staff\Support\Dates;
use App\Insights\GymInsights;
use App\Insights\Insight;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Support\Facades\Gate;

/**
 * Reads the Insights page's filters as the API's insights request does: the
 * last 3, 6 or 12 months (12 unless chosen), at the branch chosen if the
 * staff member works at it, or else all of theirs. Each widget shows only to
 * the roles its insight is for (ADR 0040).
 */
trait ReadsInsightFilters
{
    use InteractsWithPageFilters;

    abstract protected static function insight(): Insight;

    public static function canView(): bool
    {
        return Gate::allows('see-insight', static::insight());
    }

    protected function gym(): Organization
    {
        return app(CurrentOrganization::class)->get();
    }

    protected function insights(): GymInsights
    {
        return app(GymInsights::class);
    }

    protected function months(): int
    {
        $months = (int) ($this->pageFilters['months'] ?? 12);

        return in_array($months, GymInsights::MONTHS, true) ? $months : 12;
    }

    /**
     * @return list<int>|null
     */
    protected function branchIds(): ?array
    {
        $staff = app(CurrentStaff::class)->get();
        $branchId = $this->pageFilters['branch_id'] ?? null;
        $branch = is_numeric($branchId) ? Branch::query()->find((int) $branchId) : null;

        return $branch !== null && $staff->worksAt($branch) ? [$branch->id] : $staff->limitedToBranchIds();
    }

    /**
     * "Nov 2026" for "2026-11".
     */
    protected static function monthLabel(string $month): string
    {
        return Dates::month("{$month}-01");
    }
}
