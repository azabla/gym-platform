<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Gyms\Pages;

use App\Domains\Identity\Models\PasswordLink;
use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Models\Organization;
use App\Filament\Admin\Resources\Gyms\GymResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewGym extends ViewRecord
{
    protected static string $resource = GymResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pause')
                ->label('Pause gym')
                ->icon(Heroicon::OutlinedPauseCircle)
                ->color('danger')
                ->visible(fn (): bool => ! $this->gym()->isSuspended())
                ->modalIcon(Heroicon::OutlinedPauseCircle)
                ->modalHeading(fn (): string => 'Pause '.$this->gym()->name.'?')
                ->modalDescription('Its staff can\'t sign in, its members\' app shows the gym as paused, the door turns everyone away, and it sends no reminders or messages. Nothing is deleted: resuming brings everything back.')
                ->modalSubmitActionLabel('Pause gym')
                ->schema([
                    Textarea::make('reason')
                        ->label('Why')
                        ->placeholder('Hasn\'t paid for November; owner asked to close for renovation…')
                        ->helperText('Kept in the gym\'s pause history for the next admin.')
                        ->required()
                        ->maxLength(1000),
                ])
                ->action(function (array $data): void {
                    $this->gym()->suspend($this->admin(), (string) $data['reason']);
                    Notification::make()->success()->title('Gym paused.')->send();
                }),
            Action::make('resume')
                ->label('Resume gym')
                ->icon(Heroicon::OutlinedPlayCircle)
                ->color('success')
                ->visible(fn (): bool => $this->gym()->isSuspended())
                ->modalIcon(Heroicon::OutlinedPlayCircle)
                ->modalHeading(fn (): string => 'Resume '.$this->gym()->name.'?')
                ->modalDescription('Its staff, members\' app, door and messages work again as before.')
                ->modalSubmitActionLabel('Resume gym')
                ->schema([
                    Textarea::make('note')
                        ->label('Note')
                        ->placeholder('Paid on 2 December')
                        ->helperText('Optional.')
                        ->maxLength(1000),
                ])
                ->action(function (array $data): void {
                    $this->gym()->resume($this->admin(), isset($data['note']) ? (string) $data['note'] : null);
                    Notification::make()->success()->title('Gym resumed.')->send();
                }),
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

    private function admin(): User
    {
        $admin = Filament::auth()->user();

        abort_unless($admin instanceof User, 403);

        return $admin;
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
