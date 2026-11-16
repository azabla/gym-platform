<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\Branches\Pages;

use App\Domains\Organizations\Models\Branch;
use App\Filament\Staff\Resources\Branches\BranchResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBranch extends CreateRecord
{
    protected static string $resource = BranchResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Branch
    {
        return BranchResource::saveBranch(new Branch, $data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
