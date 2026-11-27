<?php

declare(strict_types=1);

namespace App\Reports;

use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Money\Money;
use App\Insights\GymInsights;
use App\Insights\Insight;

/**
 * An insight as a CSV file: one row a month, or for busiest hours one row a
 * weekday with a column an hour (ADR 0040). Amounts are plain numbers in
 * birr, so a spreadsheet can add them up.
 */
final class InsightReport extends Report
{
    private const WEEKDAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    /**
     * @param  list<int>|null  $branchIds
     */
    public function __construct(
        private readonly Insight $insight,
        private readonly Organization $organization,
        private readonly int $months,
        private readonly ?array $branchIds,
    ) {}

    public function name(): string
    {
        return $this->insight->value;
    }

    public function headings(): array
    {
        return match ($this->insight) {
            Insight::Revenue => ['Month', 'Net', ...array_map(fn (PaymentMethod $method): string => $method->label(), PaymentMethod::moneyTaken()), ...$this->plans()],
            Insight::Renewals => ['Month', 'Ended', 'Renewed', 'Lapsed', 'Waiting', 'Renewal rate'],
            Insight::BusyHours => ['Weekday', ...array_map(fn (int $hour): string => sprintf('%02d:00', $hour), range(0, 23))],
            Insight::Members => ['Month', 'Active members', 'New members', 'Visit packs ended', 'Visits bought', 'Visits used', 'Packs with visits left'],
        };
    }

    public function rows(): iterable
    {
        $insights = app(GymInsights::class);

        if ($this->insight === Insight::BusyHours) {
            foreach ($insights->busyHours($this->organization, $this->months, $this->branchIds)['grid'] as $weekday => $hours) {
                yield [self::WEEKDAYS[$weekday], ...array_map('strval', $hours)];
            }

            return;
        }

        if ($this->insight === Insight::Revenue) {
            $plans = $this->plans();

            foreach ($insights->revenue($this->organization, $this->months, $this->branchIds) as $month) {
                yield [
                    $month['month'],
                    $month['net']->toDecimal(),
                    ...array_map(fn (Money $amount): string => $amount->toDecimal(), array_values($month['by_method'])),
                    ...array_map(fn (string $plan): string => isset($month['by_plan'][$plan]) ? $month['by_plan'][$plan]->toDecimal() : '0.00', $plans),
                ];
            }

            return;
        }

        if ($this->insight === Insight::Renewals) {
            foreach ($insights->renewals($this->organization, $this->months, $this->branchIds) as $month) {
                yield [
                    $month['month'],
                    (string) $month['ended'],
                    (string) $month['renewed'],
                    (string) $month['lapsed'],
                    (string) $month['waiting'],
                    $month['rate'] === null ? '' : number_format($month['rate'] * 100, 1).'%',
                ];
            }

            return;
        }

        foreach ($insights->members($this->organization, $this->months, $this->branchIds) as $month) {
            yield [
                $month['month'],
                (string) $month['active'],
                (string) $month['new'],
                (string) $month['packs_ended'],
                (string) $month['visits_bought'],
                (string) $month['visits_used'],
                (string) $month['packs_with_visits_left'],
            ];
        }
    }

    protected function gym(): Organization
    {
        return $this->organization;
    }

    protected function describeDays(): string
    {
        return "last-{$this->months}-months";
    }

    /**
     * The plans that took money in the months covered, for revenue's columns.
     *
     * @return list<string>
     */
    private function plans(): array
    {
        if ($this->insight !== Insight::Revenue) {
            return [];
        }

        $plans = [];

        foreach (app(GymInsights::class)->revenue($this->organization, $this->months, $this->branchIds) as $month) {
            $plans = [...$plans, ...array_keys($month['by_plan'])];
        }

        $plans = array_values(array_unique($plans));
        sort($plans);

        return $plans;
    }
}
