<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\PaymentLinks\Pages;

use App\Domains\Billing\Chapa\Exceptions\ChapaRequestFailed;
use App\Domains\Billing\Enums\PaymentLinkStatus;
use App\Domains\Billing\Models\ChapaAccount;
use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Filament\Staff\Resources\PaymentLinks\PaymentLinkResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;

class ViewPaymentLink extends ViewRecord
{
    protected static string $resource = PaymentLinkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('check')
                ->label('Check now')
                ->visible(fn (PaymentLink $record): bool => $record->settled_at === null && Gate::allows('check', $record))
                ->action(function (PaymentLink $record): void {
                    $chapaAccount = ChapaAccount::query()->first();

                    if ($chapaAccount === null) {
                        Notification::make()->danger()->title('Connect the gym\'s Chapa account first.')->send();

                        return;
                    }

                    try {
                        $record->check($chapaAccount);
                    } catch (ChapaRequestFailed $exception) {
                        Notification::make()->danger()->title($exception->getMessage())->send();

                        return;
                    }

                    self::sayWhereItStands($record);
                }),
            Action::make('cancel')
                ->label('Cancel link')
                ->color('danger')
                ->visible(fn (PaymentLink $record): bool => $record->status === PaymentLinkStatus::Pending && Gate::allows('cancel', $record))
                ->requiresConfirmation()
                ->modalDescription('The member can no longer pay with this link. If they pay on Chapa\'s page anyway, the payment is still recorded while the membership owes it.')
                ->action(function (PaymentLink $record): void {
                    $record->cancel(app(CurrentStaff::class)->get());

                    Notification::make()->success()->title('The link is cancelled.')->send();
                }),
        ];
    }

    private static function sayWhereItStands(PaymentLink $paymentLink): void
    {
        match ($paymentLink->status) {
            PaymentLinkStatus::Paid => Notification::make()->success()->title("Paid. Receipt {$paymentLink->payment?->receipt_number}.")->send(),
            PaymentLinkStatus::RefundNeeded => Notification::make()->warning()->title('Chapa took the money, but nothing is due any more. Refund it from your Chapa dashboard.')->send(),
            default => Notification::make()->title('Not paid yet.')->send(),
        };
    }
}
