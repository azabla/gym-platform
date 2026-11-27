<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\Members\Pages;

use App\Domains\Imports\Models\MemberImport;
use App\Domains\Members\Models\Member;
use App\Filament\Staff\Actions\DownloadReportAction;
use App\Filament\Staff\Resources\MemberImports\MemberImportResource;
use App\Filament\Staff\Resources\Members\MemberResource;
use App\Reports\MembersReport;
use App\Reports\ReportPeriod;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

class ListMembers extends ListRecords
{
    protected static string $resource = MemberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DownloadReportAction::make()
                ->withoutAPeriod()
                ->report(Member::class, fn (ReportPeriod $period): MembersReport => new MembersReport($period->gym, $period->branchIds)),
            Action::make('import')
                ->label(__('Import members'))
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('gray')
                ->visible(fn (): bool => Gate::allows('viewAny', MemberImport::class))
                ->url(fn (): string => MemberImportResource::getUrl('index')),
            CreateAction::make()->label(__('Register member')),
        ];
    }
}
