<?php

declare(strict_types=1);

namespace App\Reports;

use App\Domains\Attendance\Enums\CheckInResult;
use App\Domains\Attendance\Models\CheckIn;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every check-in in the period at the branches, let in or turned away, with
 * the time where the gym is and the reason for a denial, oldest first. See
 * ADR 0011 and ADR 0024.
 */
final class CheckInsReport extends Report
{
    public function __construct(
        private readonly ReportPeriod $period,
        private readonly ?CheckInResult $result = null,
    ) {}

    public function name(): string
    {
        return 'check-ins';
    }

    public function headings(): array
    {
        return ['When', 'Member', 'Phone', 'Branch', 'Method', 'Result', 'Reason'];
    }

    public function rows(): iterable
    {
        foreach ($this->checkIns()->lazy(self::CHUNK) as $checkIn) {
            yield [
                $this->moment($checkIn->checked_in_at),
                $checkIn->member === null ? 'Unknown' : $checkIn->member->full_name,
                (string) $checkIn->member?->phone,
                (string) $checkIn->branch?->name,
                $checkIn->method->label(),
                $checkIn->result === CheckInResult::Allowed ? 'Let in' : 'Turned away',
                (string) $checkIn->reason_code?->label(),
            ];
        }
    }

    protected function gym(): Organization
    {
        return $this->period->gym;
    }

    protected function describeDays(): string
    {
        return $this->period->describe();
    }

    /**
     * @return Builder<CheckIn>
     */
    private function checkIns(): Builder
    {
        $branchIds = $this->period->branchIds;

        return CheckIn::query()
            ->where('checked_in_at', '>=', $this->period->startsAt())
            ->where('checked_in_at', '<', $this->period->endsBefore())
            ->madeAtAnyOf($branchIds)
            ->when($this->result !== null, fn (Builder $query) => $query->where('result', $this->result))
            ->with(['member' => fn ($query) => $query->withTrashed(), 'branch'])
            ->orderBy('checked_in_at')
            ->orderBy('id');
    }
}
