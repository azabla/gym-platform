<?php

declare(strict_types=1);

namespace App\Filament\Staff\Actions;

use App\Domains\Billing\Enums\PaymentLinkStatus;
use App\Domains\Billing\Exceptions\PaymentRefused;
use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Text an open payment link to the member's phone, for members without the
 * app, with the API's rules (SendPaymentLinkRequest): anyone who takes
 * payments at the link's branch, at most once every 10 minutes. It works on
 * a link's page and on a membership's page, for its open link. See ADR 0031.
 */
class SendPaymentLinkAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'sendBySms';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Send by SMS'))
            ->icon('heroicon-o-chat-bubble-left-ellipsis')
            ->visible(function (Model $record): bool {
                $paymentLink = self::linkOf($record);

                return $paymentLink !== null && Gate::allows('send', $paymentLink);
            })
            ->requiresConfirmation()
            ->modalDescription(fn (Model $record): string => __('The link and amount go by SMS to :phone.', ['phone' => self::linkOf($record)?->member?->phone]))
            ->action(function (Model $record, Action $action): void {
                $paymentLink = self::linkOf($record);

                if ($paymentLink === null) {
                    return;
                }

                try {
                    $paymentLink->sendToMember(CarbonImmutable::now());
                } catch (PaymentRefused $refusal) {
                    Refusals::halt($action, $refusal);
                }

                Notification::make()->success()->title(__('The link is on its way to the member by SMS.'))->send();
            });
    }

    /**
     * The link itself, or a membership's open link at the staff member's
     * branches.
     */
    private static function linkOf(Model $record): ?PaymentLink
    {
        if ($record instanceof PaymentLink) {
            return $record->status === PaymentLinkStatus::Pending ? $record : null;
        }

        if (! $record instanceof Membership) {
            return null;
        }

        return PaymentLink::query()
            ->pending()
            ->forAnyOf(app(CurrentStaff::class)->get()->limitedToBranchIds())
            ->where('membership_id', $record->id)
            ->first();
    }
}
