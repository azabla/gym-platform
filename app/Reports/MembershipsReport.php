<?php

declare(strict_types=1);

namespace App\Reports;

use App\Domains\Billing\Models\Payment;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Builder;

/**
 * The memberships sold in the period, at the desk or in the app, of members
 * whose home branch is one of the branches, as the dashboard counts them,
 * with what each cost, what was paid and what is still owed. See ADR 0014
 * and ADR 0024.
 */
final class MembershipsReport extends Report
{
    public function __construct(private readonly ReportPeriod $period) {}

    public function name(): string
    {
        return 'memberships';
    }

    public function headings(): array
    {
        return [
            'Sold on', 'Member', 'Phone', 'Home branch', 'Plan', 'Starts on', 'Ends on', 'Status today',
            'List price', 'Price', 'Discount reason', 'Paid', 'Balance due', 'Currency', 'Sold by', 'Sold in the app',
        ];
    }

    public function rows(): iterable
    {
        $today = $this->period->gym->today();

        foreach ($this->memberships()->lazy(self::CHUNK)->chunk(self::CHUNK) as $chunk) {
            $balances = Payment::balancesOf($chunk->values()->all());

            foreach ($chunk as $membership) {
                $balance = $balances[$membership->id];

                yield [
                    $this->day($membership->created_at?->setTimezone($this->period->gym->timezone)),
                    (string) $membership->member?->full_name,
                    (string) $membership->member?->phone,
                    (string) $membership->member?->homeBranch?->name,
                    (string) $membership->plan?->name,
                    $this->day($membership->starts_on),
                    $this->day($membership->ends_on),
                    ucfirst($membership->statusOn($today)->value),
                    $membership->list_price->toDecimal(),
                    $membership->price->toDecimal(),
                    (string) $membership->discount_reason,
                    $balance->amountPaid->toDecimal(),
                    $balance->balanceDue->toDecimal(),
                    $membership->price->currency->value,
                    (string) $membership->soldBy?->user?->name,
                    $membership->sold_in_app ? 'Yes' : 'No',
                ];
            }
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
     * @return Builder<Membership>
     */
    private function memberships(): Builder
    {
        $branchIds = $this->period->branchIds;

        return Membership::query()
            ->where('created_at', '>=', $this->period->startsAt())
            ->where('created_at', '<', $this->period->endsBefore())
            ->ofMembersAtAnyOf($branchIds)
            ->with(['member.homeBranch', 'plan', 'soldBy.user', 'freezes'])
            ->orderBy('created_at')
            ->orderBy('id');
    }
}
