<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\Members\Pages;

use App\Domains\Members\Models\Member;
use App\Filament\Staff\Actions\DownloadReportAction;
use App\Filament\Staff\Resources\Members\MemberResource;
use App\Reports\MembersReport;
use App\Reports\ReportPeriod;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMembers extends ListRecords
{
    protected static string $resource = MemberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DownloadReportAction::make()
                ->withoutAPeriod()
                ->report(Member::class, fn (ReportPeriod $period): MembersReport => new MembersReport($period->gym, $period->branchIds)),
            CreateAction::make()->label('Register member'),
        ];
    }
}
