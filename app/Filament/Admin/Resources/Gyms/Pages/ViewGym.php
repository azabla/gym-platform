<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Gyms\Pages;

use App\Domains\Identity\Models\PasswordLink;
use App\Domains\Organizations\Models\Organization;
use App\Filament\Admin\Resources\Gyms\GymResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewGym extends ViewRecord
{
    protected static string $resource = GymResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            Action::make('resendInvite')
                ->label('Resend owner invite')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('gray')
                ->requiresConfirmation()
                ->modalIcon(Heroicon::OutlinedPaperAirplane)
                ->modalHeading('Resend the owner\'s invite?')
                ->modalDescription(fn (): string => 'A new link to set their password goes by SMS to '.$this->ownerName().'. Earlier links stop working.')
                ->disabled(fn (): bool => ! $this->ownerNeedsAnInvite())
                ->tooltip(fn (): ?string => $this->ownerNeedsAnInvite() ? null : 'The owner has set a password. If they forgot it, they use "Forgot your password?".')
                ->action(function (): void {
                    $owner = $this->gym()->firstOwner();

                    if ($owner !== null && $owner->user->password === null) {
                        PasswordLink::invite($owner->user, $this->gym()->name);
                    }

                    Notification::make()->success()->title('Invite sent by SMS.')->send();
                }),
        ];
    }

    private function gym(): Organization
    {
        /** @var Organization */
        return $this->getRecord();
    }

    private function ownerNeedsAnInvite(): bool
    {
        $owner = $this->gym()->firstOwner();

        return $owner !== null && $owner->user->password === null;
    }

    private function ownerName(): string
    {
        $owner = $this->gym()->firstOwner();

        return $owner === null ? 'the owner' : $owner->user->name;
    }
}
