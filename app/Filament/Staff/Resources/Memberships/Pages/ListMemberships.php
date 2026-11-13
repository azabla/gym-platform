<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\Memberships\Pages;

use App\Domains\Memberships\Models\Membership;
use App\Filament\Staff\Actions\DownloadReportAction;
use App\Filament\Staff\Resources\Memberships\MembershipResource;
use App\Reports\MembershipsReport;
use App\Reports\ReportPeriod;
use Filament\Resources\Pages\ListRecords;

class ListMemberships extends ListRecords
{
    protected static string $resource = MembershipResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DownloadReportAction::make()
                ->label('Download memberships sold')
                ->report(Membership::class, fn (ReportPeriod $period): MembershipsReport => new MembershipsReport($period)),
        ];
    }
}
