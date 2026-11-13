<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\Payments\Pages;

use App\Domains\Billing\Models\Payment;
use App\Filament\Staff\Actions\DownloadReportAction;
use App\Filament\Staff\Resources\Payments\PaymentResource;
use App\Reports\PaymentsReport;
use App\Reports\ReportPeriod;
use Filament\Resources\Pages\ListRecords;

class ListPayments extends ListRecords
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DownloadReportAction::make()->report(Payment::class, fn (ReportPeriod $period): PaymentsReport => new PaymentsReport($period)),
        ];
    }
}
