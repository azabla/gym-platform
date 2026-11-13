<?php

declare(strict_types=1);

namespace App\Reports;

use App\Domains\Billing\Models\Payment;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Money\Money;
use Illuminate\Database\Eloquent\Builder;

/**
 * The gym's members still with it, at the branches (by home branch), by
 * name, each with their membership at a glance and what they still owe, as
 * the members list shows them. Archived members are left out. See ADR 0024.
 */
final class MembersReport extends Report
{
    /**
     * @param  list<int>|null  $branchIds
     */
    public function __construct(
        private readonly Organization $gym,
        private readonly ?array $branchIds = null,
        private readonly ?string $search = null,
    ) {}

    public function name(): string
    {
        return 'members';
    }

    public function headings(): array
    {
        return [
            'Name', 'Phone', 'Email', 'Home branch', 'Registered on',
            'Membership', 'Ends on', 'Status today', 'Balance due', 'Currency',
        ];
    }

    public function rows(): iterable
    {
        $today = $this->gym->today();

        foreach ($this->members()->lazy(self::CHUNK)->chunk(self::CHUNK) as $chunk) {
            $memberIds = array_map('intval', $chunk->pluck('id')->all());
            $memberships = Membership::atAGlanceFor($memberIds, $today);
            $balancesDue = Payment::balancesDueOf($memberIds);

            foreach ($chunk as $member) {
                $membership = $memberships->get($member->id);
                $balanceDue = $balancesDue[$member->id] ?? Money::zero();

                yield [
                    $member->full_name,
                    $member->phone,
                    (string) $member->email,
                    (string) $member->homeBranch?->name,
                    $this->day($member->created_at?->setTimezone($this->gym->timezone)),
                    (string) $membership?->plan?->name,
                    $this->day($membership?->ends_on),
                    $membership === null ? '' : ucfirst($membership->statusOn($today)->value),
                    $balanceDue->toDecimal(),
                    $balanceDue->currency->value,
                ];
            }
        }
    }

    protected function gym(): Organization
    {
        return $this->gym;
    }

    /**
     * The members as they are today.
     */
    protected function describeDays(): string
    {
        return $this->gym->today()->toDateString();
    }

    /**
     * @return Builder<Member>
     */
    private function members(): Builder
    {
        $branchIds = $this->branchIds;
        $search = trim((string) $this->search);

        return Member::query()
            ->homeAtAnyOf($branchIds)
            ->when($search !== '', fn (Builder $query) => $query->search($search))
            ->with('homeBranch')
            ->orderBy('full_name')
            ->orderBy('id');
    }
}
