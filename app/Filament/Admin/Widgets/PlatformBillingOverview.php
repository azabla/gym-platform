<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Domains\PlatformBilling\Enums\GymBillStatus;
use App\Domains\PlatformBilling\GymBills;
use App\Filament\Admin\Resources\GymInvoices\GymInvoiceResource;
use App\Filament\Admin\Resources\Gyms\GymResource;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The platform's bill to gyms at a glance, in counts and totals (ADR 0045).
 */
class PlatformBillingOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $figures = app(GymBills::class)->platformFigures();

        return [
            Stat::make('Owed by gyms', $figures['owed']->format())
                ->description('On invoices not yet paid')
                ->descriptionIcon(Heroicon::OutlinedDocumentText)
                ->color($figures['owed']->isPositive() ? 'warning' : 'gray')
                ->url(GymInvoiceResource::getUrl('index')),
            Stat::make('Overdue gyms', (string) $figures['overdue_gyms'])
                ->description('Past the due day, not paid')
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color($figures['overdue_gyms'] > 0 ? 'danger' : 'gray')
                ->url(GymResource::getUrl('index', ['filters' => ['bill' => ['value' => GymBillStatus::Overdue->value]]])),
            Stat::make('Collected this month', $figures['collected_this_month']->format())
                ->description('Payments recorded on invoices')
                ->descriptionIcon(Heroicon::OutlinedBanknotes)
                ->color('success'),
            Stat::make('Gyms on trial', (string) $figures['on_trial'])
                ->descriptionIcon(Heroicon::OutlinedGift)
                ->color('info')
                ->url(GymResource::getUrl('index', ['filters' => ['bill' => ['value' => GymBillStatus::Trial->value]]])),
        ];
    }
}
