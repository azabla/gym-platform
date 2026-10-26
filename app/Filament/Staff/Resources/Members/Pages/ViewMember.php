<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\Members\Pages;

use App\Filament\Staff\Actions\CheckInAction;
use App\Filament\Staff\Actions\SellMembershipAction;
use App\Filament\Staff\Resources\CheckIns\CheckInResource;
use App\Filament\Staff\Resources\Members\MemberResource;
use App\Filament\Staff\Resources\Memberships\MembershipResource;
use App\Filament\Staff\Resources\Payments\PaymentResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * A member, with links to their memberships, payments and check-ins: the
 * Members domain doesn't know about those, so they are lists filtered to the
 * member rather than relations.
 */
class ViewMember extends ViewRecord
{
    protected static string $resource = MemberResource::class;

    protected function getHeaderActions(): array
    {
        $filteredToMember = ['filters' => ['member' => ['value' => $this->getRecord()->getKey()]]];

        return [
            CheckInAction::make(),
            SellMembershipAction::make(),
            Action::make('memberships')
                ->url(MembershipResource::getUrl('index', $filteredToMember))
                ->visible(MembershipResource::canViewAny()),
            Action::make('payments')
                ->url(PaymentResource::getUrl('index', $filteredToMember))
                ->visible(PaymentResource::canViewAny()),
            Action::make('checkIns')
                ->label('Check-ins')
                ->url(CheckInResource::getUrl('index', $filteredToMember))
                ->visible(CheckInResource::canViewAny()),
            EditAction::make(),
            DeleteAction::make()->label('Archive')->modalHeading('Archive this member?'),
        ];
    }
}
