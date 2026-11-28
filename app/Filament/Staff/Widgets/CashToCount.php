<?php

declare(strict_types=1);

namespace App\Filament\Staff\Widgets;

use App\Domains\Billing\Models\CashCount;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Domains\Shared\Money\Money;
use App\Filament\Staff\Resources\CashCounts\CashCountResource;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Gate;

/**
 * Cash taken and not yet counted (ADR 0046): the staff member's own, to close
 * at the end of the shift, and, for those who see everyone's counts, the
 * staff at their branches still to close theirs, with unchecked counts.
 */
class CashToCount extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    public static function canView(): bool
    {
        return Gate::allows('create', CashCount::class);
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $staff = app(CurrentStaff::class)->get();
        $branchIds = $staff->limitedToBranchIds();
        $waiting = CashCount::waitingToBeCounted($branchIds);
        $mine = array_values(array_filter($waiting, fn (array $row): bool => $row['staff_id'] === $staff->id));
        $stats = [];

        if ($mine !== []) {
            $stats[] = Stat::make(__('Your cash to count'), self::total($mine)->format())
                ->description(__('Close your cash at the end of your shift'))
                ->descriptionIcon(Heroicon::OutlinedCalculator)
                ->color('warning')
                ->url(CashCountResource::getUrl('index'));
        }

        if (Gate::allows('seeEveryones', CashCount::class)) {
            $others = array_values(array_filter($waiting, fn (array $row): bool => $row['staff_id'] !== $staff->id));
            $people = count(array_unique(array_column($others, 'staff_id')));
            $unchecked = CashCount::query()->unchecked()->when($branchIds !== null, fn ($query) => $query->whereIn('branch_id', $branchIds))->count();

            if ($people > 0) {
                $stats[] = Stat::make(__('Cash not yet counted'), self::total($others)->format())
                    ->description(trans_choice('{1} By 1 staff member|[2,*] By :count staff members', $people))
                    ->descriptionIcon(Heroicon::OutlinedUsers)
                    ->color('gray');
            }

            if ($unchecked > 0) {
                $stats[] = Stat::make(__('Counts to check'), (string) $unchecked)
                    ->description(__('Closed and not yet checked'))
                    ->descriptionIcon(Heroicon::OutlinedCheckBadge)
                    ->color('info')
                    ->url(CashCountResource::getUrl('index', ['filters' => ['checked' => ['value' => false]]]));
            }
        }

        return $stats;
    }

    /**
     * @param  list<array{staff_id: int, branch_id: int, amount: Money}>  $rows
     */
    private static function total(array $rows): Money
    {
        return array_reduce($rows, fn (Money $total, array $row): Money => $total->add($row['amount']), Money::zero());
    }
}
