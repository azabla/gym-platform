<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\Branches\Pages;

use App\Domains\Organizations\Models\Branch;
use App\Filament\Staff\Resources\Branches\BranchResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditBranch extends EditRecord
{
    protected static string $resource = BranchResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $branch = $this->getRecord();

        assert($branch instanceof Branch);

        return [...$data, 'hours' => BranchResource::weekOf($branch)];
    }

    /**
     * @param  Branch  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Branch
    {
        return BranchResource::saveBranch($record, $data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
