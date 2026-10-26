<?php

declare(strict_types=1);

namespace App\Filament\Staff\Widgets;

use App\Dashboards\StaffDashboard;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Domains\Shared\Money\Money;
use App\Filament\Staff\Widgets\Concerns\ReadsDashboardFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Money taken and owed, for the roles that see the gym's money.
 */
class MoneyOverview extends StatsOverviewWidget
{
    use ReadsDashboardFilters;

    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        return app(CurrentStaff::class)->get()->role->seesMoney();
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $dashboard = app(StaffDashboard::class);
        $money = $dashboard->money($this->from(), $this->to(), $this->branchIds());
        $owed = $dashboard->owed($this->branchIds());

        /** @var Money $received */
        $received = $money['received'];
        /** @var Money $refunded */
        $refunded = $money['refunded'];
        /** @var Money $net */
        $net = $money['net'];
        /** @var array{count: int, amount: Money} $openLinks */
        $openLinks = $money['open_payment_links'];

        return [
            Stat::make('Taken', $net->format())
                ->description("Received {$received->format()}, refunded {$refunded->format()}"),
            Stat::make('Owed', $owed['total']->format())
                ->description(trans_choice('{0} No member owes|{1} By 1 member|[2,*] By :count members', $owed['members'])),
            Stat::make('Open payment links', $openLinks['amount']->format())
                ->description(trans_choice('{0} None waiting|{1} 1 waiting to be paid|[2,*] :count waiting to be paid', $openLinks['count'])),
        ];
    }
}
