<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\Memberships\Pages;

use App\Filament\Staff\Actions\CancelMembershipAction;
use App\Filament\Staff\Actions\EndFreezeAction;
use App\Filament\Staff\Actions\FreezeMembershipAction;
use App\Filament\Staff\Actions\RefundAction;
use App\Filament\Staff\Actions\TakePaymentAction;
use App\Filament\Staff\Resources\Memberships\MembershipResource;
use Filament\Resources\Pages\ViewRecord;

class ViewMembership extends ViewRecord
{
    protected static string $resource = MembershipResource::class;

    protected function getHeaderActions(): array
    {
        return [
            TakePaymentAction::make(),
            RefundAction::make(),
            FreezeMembershipAction::make(),
            EndFreezeAction::make(),
            CancelMembershipAction::make(),
        ];
    }
}
