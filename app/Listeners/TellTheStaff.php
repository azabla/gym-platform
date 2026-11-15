<?php

namespace App\Listeners;

use App\Domains\Billing\Enums\PaymentType;
use App\Domains\Billing\Events\PaymentLinkNeedsRefund;
use App\Domains\Billing\Events\PaymentRecorded;
use App\Domains\Memberships\Events\MembershipFreezeEnded;
use App\Domains\Memberships\Events\MembershipFrozen;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Notifications\MemberEndedFreeze;
use App\Notifications\MemberFroze;
use App\Notifications\OnlinePaymentReceived;
use App\Notifications\RefundNeeded;
use App\Notifications\StaffRecipients;
use Closure;
use Illuminate\Support\Facades\Notification;

/**
 * Tells a gym's staff what needs them (ADR 0027): money paid online, money
 * Chapa took that must be refunded, and freezes members made or ended in the
 * app. The domains only announce events; this is
 * where they become messages to staff, by role and branch (StaffRecipients).
 * Each runs at the event's gym, as a payment confirmed by Chapa comes with no
 * request's gym. Laravel finds the handle methods by the event each takes.
 */
final class TellTheStaff
{
    public function __construct(private CurrentOrganization $currentOrganization) {}

    /**
     * A payment made online, through Chapa; payments taken at the desk were
     * taken by staff, who know.
     */
    public function handlePaymentRecorded(PaymentRecorded $event): void
    {
        $payment = $event->payment;

        if ($payment->type !== PaymentType::Payment || ! $payment->method->isOnline()) {
            return;
        }

        $this->atTheGym($payment->organization_id, fn () => Notification::send(
            StaffRecipients::forMoneyAt($payment->branch),
            OnlinePaymentReceived::for($payment),
        ));
    }

    public function handlePaymentLinkNeedsRefund(PaymentLinkNeedsRefund $event): void
    {
        $paymentLink = $event->paymentLink;

        $this->atTheGym($paymentLink->organization_id, fn () => Notification::send(
            StaffRecipients::forMoneyAt($paymentLink->branch),
            RefundNeeded::for($paymentLink),
        ));
    }

    /**
     * A member froze their membership in the app; staff freezes were made by
     * staff, who know.
     */
    public function handleMembershipFrozen(MembershipFrozen $event): void
    {
        $freeze = $event->freeze;

        if (! $freeze->isFromTheApp()) {
            return;
        }

        $this->atTheGym($freeze->organization_id, fn () => Notification::send(
            StaffRecipients::forMembersAt($freeze->membership->member->homeBranch),
            MemberFroze::for($freeze),
        ));
    }

    /**
     * A member ended a freeze early in the app.
     */
    public function handleMembershipFreezeEnded(MembershipFreezeEnded $event): void
    {
        $membership = $event->membership;

        if (! $event->endedInTheApp) {
            return;
        }

        $this->atTheGym($membership->organization_id, fn () => Notification::send(
            StaffRecipients::forMembersAt($membership->member->homeBranch),
            MemberEndedFreeze::for($membership),
        ));
    }

    /**
     * @param  Closure(): void  $tell
     */
    private function atTheGym(int $organizationId, Closure $tell): void
    {
        $this->currentOrganization->run(Organization::query()->findOrFail($organizationId), $tell);
    }
}
