<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\CheckIns\Pages;

use App\Domains\Attendance\Models\CheckIn;
use App\Filament\Staff\Actions\DownloadReportAction;
use App\Filament\Staff\Resources\CheckIns\CheckInResource;
use App\Reports\CheckInsReport;
use App\Reports\ReportPeriod;
use Filament\Resources\Pages\ListRecords;

class ListCheckIns extends ListRecords
{
    protected static string $resource = CheckInResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DownloadReportAction::make()->report(CheckIn::class, fn (ReportPeriod $period): CheckInsReport => new CheckInsReport($period)),
        ];
    }
}
