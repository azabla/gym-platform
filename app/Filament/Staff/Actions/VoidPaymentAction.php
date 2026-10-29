<?php

declare(strict_types=1);

namespace App\Filament\Staff\Actions;

use App\Domains\Billing\Exceptions\PaymentRefused;
use App\Domains\Billing\Models\Payment;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;

/**
 * Mark a payment or refund as recorded by mistake, with a reason. It stays on
 * record but no longer counts. Owners, managers and accountants only. See
 * ADR 0008 and ADR 0018.
 */
class VoidPaymentAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'void';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Void')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (Payment $record): bool => Gate::allows('void', $record) && $record->voided_at === null)
            ->schema([
                Textarea::make('reason')->required()->maxLength(500),
            ])
            ->action(function (array $data, Payment $record, Action $action): void {
                $refusal = $record->refusalToVoid();

                if ($refusal !== null) {
                    Refusals::halt($action, $refusal);
                }

                try {
                    $record->void(app(CurrentStaff::class)->get(), (string) $data['reason']);
                } catch (PaymentRefused $refusal) {
                    Refusals::halt($action, $refusal);
                }

                Notification::make()->success()->title("Receipt {$record->receipt_number} voided.")->send();
            });
    }
}
