<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\MemberImports\Pages;

use App\Domains\Imports\Csv\MemberCsv;
use App\Domains\Imports\Exceptions\FileRefused;
use App\Domains\Imports\Models\MemberImport;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Filament\Staff\Resources\MemberImports\MemberImportResource;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListMemberImports extends ListRecords
{
    protected static string $resource = MemberImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('template')
                ->label(__('Download template'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(fn (): StreamedResponse => response()->streamDownload(
                    function (): void {
                        echo "\xEF\xBB\xBF".MemberCsv::template();
                    },
                    'members-template.csv',
                    ['Content-Type' => 'text/csv; charset=UTF-8'],
                )),
            Action::make('upload')
                ->label(__('Upload file'))
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->visible(fn (): bool => Gate::allows('create', MemberImport::class))
                ->modalIcon(Heroicon::OutlinedArrowUpTray)
                ->modalHeading(__('Upload a file of members'))
                ->modalDescription(__('A CSV file saved as "CSV UTF-8", with the template\'s columns. Each row is checked first: nothing is imported until you confirm.'))
                ->modalSubmitActionLabel(__('Check the file'))
                ->schema([
                    FileUpload::make('file')
                        ->label(__('File'))
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/csv'])
                        ->maxSize(1024)
                        ->storeFiles(false)
                        ->required()
                        ->helperText(__('Up to :count rows and 1 MB.', ['count' => MemberCsv::MAX_ROWS])),
                ])
                ->action(function (array $data, Action $action): void {
                    $file = $data['file'];

                    abort_unless($file instanceof TemporaryUploadedFile, 422);

                    try {
                        $import = MemberImport::check(
                            app(CurrentStaff::class)->get(),
                            $file->getClientOriginalName(),
                            (string) file_get_contents($file->getRealPath()),
                            app(CurrentOrganization::class)->get()->today(),
                        );
                    } catch (FileRefused $refusal) {
                        Notification::make()->danger()->title($refusal->getMessage())->send();
                        $action->halt();

                        return;
                    }

                    $this->redirect(MemberImportResource::getUrl('view', ['record' => $import]));
                }),
        ];
    }
}
