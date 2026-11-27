<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\PaymentLinks\Pages;

use App\Domains\Billing\Chapa\Exceptions\ChapaRequestFailed;
use App\Domains\Billing\Enums\PaymentLinkStatus;
use App\Domains\Billing\Exceptions\PaymentRefused;
use App\Domains\Billing\Models\ChapaAccount;
use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Filament\Staff\Actions\Refusals;
use App\Filament\Staff\Actions\SendPaymentLinkAction;
use App\Filament\Staff\Resources\PaymentLinks\PaymentLinkResource;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
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
                ->label(__('Check now'))
                ->visible(fn (PaymentLink $record): bool => $record->settled_at === null && Gate::allows('check', $record))
                ->action(function (PaymentLink $record): void {
                    $chapaAccount = ChapaAccount::query()->first();

                    if ($chapaAccount === null) {
                        Notification::make()->danger()->title(__('Connect the gym\'s Chapa account first.'))->send();

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
                ->label(__('Cancel link'))
                ->color('danger')
                ->visible(fn (PaymentLink $record): bool => $record->status === PaymentLinkStatus::Pending && Gate::allows('cancel', $record))
                ->requiresConfirmation()
                ->modalDescription(__('The member can no longer pay with this link. If they pay on Chapa\'s page anyway, the payment is still recorded while the membership owes it.'))
                ->action(function (PaymentLink $record): void {
                    $record->cancel(app(CurrentStaff::class)->get());

                    Notification::make()->success()->title(__('The link is cancelled.'))->send();
                }),
            SendPaymentLinkAction::make(),
            Action::make('markRefunded')
                ->label(__('Mark refunded'))
                ->color('warning')
                ->visible(fn (PaymentLink $record): bool => $record->status === PaymentLinkStatus::RefundNeeded && Gate::allows('markRefunded', $record))
                ->modalDescription(__('Once you have given the money back from your Chapa dashboard. The link then shows as refunded.'))
                ->schema([
                    TextInput::make('note')
                        ->label(__('Note'))
                        ->maxLength(255)
                        ->helperText(__('Optional, such as Chapa\'s refund reference.')),
                ])
                ->action(function (array $data, PaymentLink $record, Action $action): void {
                    try {
                        $record->markRefunded(app(CurrentStaff::class)->get(), filled($data['note'] ?? null) ? (string) $data['note'] : null);
                    } catch (PaymentRefused $refusal) {
                        Refusals::halt($action, $refusal);
                    }

                    Notification::make()->success()->title(__('Marked refunded.'))->send();
                }),
        ];
    }

    private static function sayWhereItStands(PaymentLink $paymentLink): void
    {
        match ($paymentLink->status) {
            PaymentLinkStatus::Paid => Notification::make()->success()->title(__('Paid. Receipt :receipt.', ['receipt' => $paymentLink->payment?->receipt_number]))->send(),
            PaymentLinkStatus::RefundNeeded => Notification::make()->warning()->title(__('Chapa took the money, but nothing is due any more. Refund it from your Chapa dashboard.'))->send(),
            default => Notification::make()->title(__('Not paid yet.'))->send(),
        };
    }
}
