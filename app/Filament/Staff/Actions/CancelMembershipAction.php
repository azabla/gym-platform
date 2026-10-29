<?php

declare(strict_types=1);

namespace App\Filament\Staff\Actions;

use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;

/**
 * Cancel a membership that is going on or still to come, with a reason. Only
 * owners and managers may. What was paid stays paid until it is refunded. See
 * ADR 0008 and ADR 0018.
 */
class CancelMembershipAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'cancel';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Cancel membership')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (Membership $record): bool => Gate::allows('cancel', $record))
            ->schema([
                Textarea::make('reason')->required()->maxLength(500),
            ])
            ->action(function (array $data, Membership $record, Action $action): void {
                $refusal = $record->refusalToCancel(app(CurrentOrganization::class)->get()->today());

                if ($refusal !== null) {
                    Refusals::halt($action, $refusal);
                }

                $record->cancel(app(CurrentStaff::class)->get(), (string) $data['reason']);

                Notification::make()->success()->title('Membership cancelled.')->send();
            });
    }
}
