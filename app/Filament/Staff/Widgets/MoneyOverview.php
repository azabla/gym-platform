<?php

declare(strict_types=1);

namespace App\Filament\Staff\Widgets;

use App\Dashboards\StaffDashboard;
use App\Domains\Billing\Enums\PaymentLinkStatus;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Domains\Shared\Money\Money;
use App\Filament\Staff\Resources\Memberships\MembershipResource;
use App\Filament\Staff\Resources\PaymentLinks\PaymentLinkResource;
use App\Filament\Staff\Widgets\Concerns\ReadsDashboardFilters;
use Filament\Support\Icons\Heroicon;
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
        /** @var array{count: int, amount: Money} $refundsNeeded */
        $refundsNeeded = $money['refunds_needed'];

        $stats = [
            Stat::make('Taken', $net->format())
                ->description("Received {$received->format()}, refunded {$refunded->format()}")
                ->descriptionIcon(Heroicon::OutlinedBanknotes)
                ->color('success')
                ->chart($this->takingsOfTheLastWeek()),
            Stat::make('Owed', $owed['total']->format())
                ->description(trans_choice('{0} No member owes|{1} By 1 member|[2,*] By :count members', $owed['members']))
                ->descriptionIcon(Heroicon::OutlinedExclamationCircle)
                ->color($owed['members'] > 0 ? 'warning' : 'gray')
                ->url(MembershipResource::getUrl('index')),
            Stat::make('Open payment links', $openLinks['amount']->format())
                ->description(trans_choice('{0} None waiting|{1} 1 waiting to be paid|[2,*] :count waiting to be paid', $openLinks['count']))
                ->descriptionIcon(Heroicon::OutlinedLink)
                ->color($openLinks['count'] > 0 ? 'info' : 'gray')
                ->url(PaymentLinkResource::getUrl('index', ['filters' => ['status' => ['value' => PaymentLinkStatus::Pending->value]]])),
        ];

        // Money Chapa took when nothing was due, still to give back (ADR 0031).
        if ($refundsNeeded['count'] > 0) {
            $stats[] = Stat::make('Refunds to make', $refundsNeeded['amount']->format())
                ->description(trans_choice('{1} 1 payment link|[2,*] :count payment links', $refundsNeeded['count']))
                ->descriptionIcon(Heroicon::OutlinedArrowUturnLeft)
                ->color('danger')
                ->url(PaymentLinkResource::getUrl('index', ['filters' => ['status' => ['value' => PaymentLinkStatus::RefundNeeded->value]]]));
        }

        return $stats;
    }

    /**
     * What was taken on each of the seven days up to the period's end, for
     * the Taken stat's sparkline.
     *
     * @return list<float>
     */
    private function takingsOfTheLastWeek(): array
    {
        $trends = app(StaffDashboard::class)->trends($this->gym(), $this->to()->subDays(6), $this->to(), $this->branchIds(), seesMoney: true);

        /** @var list<array{date: string, net: Money}> $days */
        $days = $trends['takings_by_day'] ?? [];

        return array_map(fn (array $day): float => (float) $day['net']->toDecimal(), $days);
    }
}
