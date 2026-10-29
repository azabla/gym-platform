<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\Members\Pages;

use App\Domains\Members\Models\Member;
use App\Filament\Staff\Resources\Members\MemberResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMember extends CreateRecord
{
    protected static string $resource = MemberResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Member
    {
        return MemberResource::fillMember(new Member, $data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
