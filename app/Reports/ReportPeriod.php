<?php

declare(strict_types=1);

namespace App\Reports;

use App\Domains\Organizations\Models\Organization;
use Carbon\CarbonImmutable;

/**
 * The days a report covers at a gym, both included, in the gym's time zone,
 * and the branches it covers: null means every branch. See ADR 0024.
 */
final readonly class ReportPeriod
{
    /**
     * @param  list<int>|null  $branchIds
     */
    public function __construct(
        public Organization $gym,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public ?array $branchIds = null,
    ) {}

    /**
     * The moment the first day begins where the gym is, in UTC.
     */
    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->from->toDateString(), $this->gym->timezone)->utc();
    }

    /**
     * The moment the day after the last one begins where the gym is, in UTC.
     */
    public function endsBefore(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->to->addDay()->toDateString(), $this->gym->timezone)->utc();
    }

    /**
     * The days, for a file name: 2026-11-01-to-2026-11-30, or one day alone.
     */
    public function describe(): string
    {
        $from = $this->from->toDateString();
        $to = $this->to->toDateString();

        return $from === $to ? $from : "{$from}-to-{$to}";
    }
}
