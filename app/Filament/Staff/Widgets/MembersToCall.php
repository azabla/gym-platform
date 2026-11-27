<?php

declare(strict_types=1);

namespace App\Filament\Staff\Widgets;

use App\Dashboards\StaffDashboard;
use App\Domains\Shared\Money\Money;
use App\Filament\Staff\Widgets\Concerns\ReadsDashboardFilters;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Memberships ending in the next week, and visit packs nearly used up, with
 * nothing sold to follow them: the members to call. The balance shows only to
 * the roles that see the gym's money.
 */
class MembersToCall extends TableWidget
{
    use ReadsDashboardFilters;

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $seesMoney = $this->staff()->role->seesMoney();

        return $table
            ->heading(__('Members to call'))
            ->records(fn (): array => $this->membersToCall($seesMoney))
            ->columns([
                TextColumn::make('full_name')->label(__('Member')),
                TextColumn::make('phone')->label(__('Phone')),
                TextColumn::make('plan')->label(__('Plan')),
                TextColumn::make('ends_on')->label(__('Ends on'))->date(),
                TextColumn::make('visits_remaining')->label(__('Visits left'))->placeholder(__('No limit')),
                TextColumn::make('balance_due')->label(__('Balance due'))->visible($seesMoney),
            ])
            ->emptyStateHeading(__('No one to call'))
            ->paginated(false);
    }

    /**
     * The expiring list, keyed by membership.
     *
     * @return array<int, array<string, mixed>>
     */
    private function membersToCall(bool $seesMoney): array
    {
        $records = [];

        foreach (app(StaffDashboard::class)->expiring($this->gym(), $this->branchIds(), $seesMoney) as $membership) {
            $balanceDue = $membership['balance_due'] ?? null;

            $records[$membership['membership_id']] = [
                'full_name' => $membership['member']['full_name'],
                'phone' => $membership['member']['phone'],
                'plan' => $membership['plan']['name'],
                'ends_on' => $membership['ends_on'],
                'visits_remaining' => $membership['visits_remaining'] ?? null,
                'balance_due' => $balanceDue instanceof Money ? $balanceDue->format() : null,
            ];
        }

        return $records;
    }
}
