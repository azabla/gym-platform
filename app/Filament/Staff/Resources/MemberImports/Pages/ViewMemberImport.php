<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\MemberImports\Pages;

use App\Domains\Imports\Enums\MemberImportStatus;
use App\Domains\Imports\Models\MemberImport;
use App\Filament\Staff\Resources\MemberImports\MemberImportResource;
use App\Jobs\ImportMembers;
use App\Reports\Csv;
use App\Reports\RowsNotImportedReport;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ViewMemberImport extends ViewRecord
{
    protected static string $resource = MemberImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')
                ->label(fn (): string => trans_choice('{1} Import 1 member|[2,*] Import :count members', $this->import()->ready_count))
                ->icon(Heroicon::OutlinedArrowDownOnSquareStack)
                ->visible(fn (): bool => $this->import()->status === MemberImportStatus::Checked && $this->import()->ready_count > 0)
                ->requiresConfirmation()
                ->modalIcon(Heroicon::OutlinedArrowDownOnSquareStack)
                ->modalHeading(__('Import these members?'))
                ->modalDescription(fn (): string => __(':ready members are added with their memberships and what they paid; money paid before is recorded as brought in, not as money taken. No one gets a message. :skipped rows are left out.', [
                    'ready' => $this->import()->ready_count,
                    'skipped' => $this->import()->error_count + $this->import()->duplicate_count,
                ]))
                ->modalSubmitActionLabel(__('Import'))
                ->action(function (): void {
                    ImportMembers::dispatch($this->import());
                    Notification::make()->success()->title(__('Importing. The rows below fill in as it goes.'))->send();
                }),
            Action::make('discard')
                ->label(__('Discard'))
                ->icon(Heroicon::OutlinedTrash)
                ->color('gray')
                ->visible(fn (): bool => $this->import()->status === MemberImportStatus::Checked)
                ->requiresConfirmation()
                ->modalHeading(__('Discard this file?'))
                ->modalDescription(__('Nothing from it is imported. You can upload it again later.'))
                ->action(function (): void {
                    $this->import()->discard();
                    Notification::make()->success()->title(__('File discarded.'))->send();
                }),
            Action::make('notImported')
                ->label(__('Download rows not imported'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible(fn (): bool => $this->import()->status !== MemberImportStatus::Checked
                    && $this->import()->error_count + $this->import()->duplicate_count + $this->import()->failed_count > 0)
                ->action(fn (): StreamedResponse => Csv::download(new RowsNotImportedReport($this->import()))),
        ];
    }

    private function import(): MemberImport
    {
        /** @var MemberImport */
        return $this->getRecord();
    }
}
