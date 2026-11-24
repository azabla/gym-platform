<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\Members\Pages;

use App\Domains\Members\Models\Member;
use App\Domains\Members\Photos\MemberPhoto;
use App\Filament\Staff\Actions\CheckInAction;
use App\Filament\Staff\Actions\DiscountGroupAction;
use App\Filament\Staff\Actions\MemberPhotoAction;
use App\Filament\Staff\Actions\SellMembershipAction;
use App\Filament\Staff\Resources\CheckIns\CheckInResource;
use App\Filament\Staff\Resources\Members\MemberResource;
use App\Filament\Staff\Resources\Memberships\MembershipResource;
use App\Filament\Staff\Resources\Payments\PaymentResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

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
            DiscountGroupAction::make(),
            MemberPhotoAction::make(),
            Action::make('removePhoto')
                ->label('Remove photo')
                ->icon(Heroicon::OutlinedTrash)
                ->color('gray')
                ->visible(fn (Member $record): bool => $record->photo_path !== null && Gate::allows('update', $record))
                ->requiresConfirmation()
                ->modalIcon(Heroicon::OutlinedCamera)
                ->modalDescription('The member\'s photo is deleted. The desk can add another, or the member can from the app.')
                ->action(function (Member $record): void {
                    app(MemberPhoto::class)->remove($record);

                    Notification::make()->success()->title('Photo removed.')->send();
                }),
            Action::make('memberships')
                ->icon(Heroicon::OutlinedIdentification)
                ->color('gray')
                ->url(MembershipResource::getUrl('index', $filteredToMember))
                ->visible(MembershipResource::canViewAny()),
            Action::make('payments')
                ->icon(Heroicon::OutlinedBanknotes)
                ->color('gray')
                ->url(PaymentResource::getUrl('index', $filteredToMember))
                ->visible(PaymentResource::canViewAny()),
            Action::make('checkIns')
                ->label('Check-ins')
                ->icon(Heroicon::OutlinedArrowRightEndOnRectangle)
                ->color('gray')
                ->url(CheckInResource::getUrl('index', $filteredToMember))
                ->visible(CheckInResource::canViewAny()),
            EditAction::make(),
            DeleteAction::make()->label('Archive')->modalHeading('Archive this member?'),
        ];
    }
}
