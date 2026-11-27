<?php

declare(strict_types=1);

namespace App\Insights;

use App\Domains\Organizations\Models\Organization;

/**
 * An insight's figures for the gym, the months and the branches, the same
 * for the panel, the staff API and the CSV files (ADR 0040).
 */
final class InsightFigures
{
    public function __construct(private GymInsights $insights) {}

    /**
     * @param  list<int>|null  $branchIds
     * @return array<string, mixed>
     */
    public function of(Insight $insight, Organization $gym, int $months, ?array $branchIds): array
    {
        return match ($insight) {
            Insight::Revenue => ['months' => $this->insights->revenue($gym, $months, $branchIds)],
            Insight::Renewals => ['months' => $this->insights->renewals($gym, $months, $branchIds)],
            Insight::BusyHours => $this->insights->busyHours($gym, $months, $branchIds),
            Insight::Members => ['months' => $this->insights->members($gym, $months, $branchIds)],
        };
    }
}
