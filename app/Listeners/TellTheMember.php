<?php

namespace App\Listeners;

use App\Domains\Attendance\Events\CheckInDenied;
use App\Domains\Billing\Enums\PaymentType;
use App\Domains\Billing\Events\PaymentRecorded;
use App\Domains\Memberships\Events\MembershipFrozen as MembershipWasFrozen;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Notifications\MembershipFrozen;
use App\Notifications\PaymentSucceeded;
use App\Notifications\VisitDenied;
use Closure;

/**
 * Tells members what happened to them at a gym (ADR 0012): a payment
 * recorded, a freeze, a check-in turned away. The domains only announce
 * events; this is where they become messages. Each runs at the event's gym,
 * because it may run after the request's gym is gone, such as for a
 * payment confirmed by Chapa. Laravel finds the handle methods by the event
 * each takes.
 */
final class TellTheMember
{
    public function __construct(private CurrentOrganization $currentOrganization) {}

    public function handlePaymentRecorded(PaymentRecorded $event): void
    {
        $payment = $event->payment;

        if ($payment->type !== PaymentType::Payment) {
            return;
        }

        $this->atTheGym($payment->organization_id, fn () => $payment->membership->member->notify(PaymentSucceeded::for($payment)));
    }

    public function handleMembershipFrozen(MembershipWasFrozen $event): void
    {
        $freeze = $event->freeze;

        $this->atTheGym($freeze->organization_id, fn () => $freeze->membership->member->notify(MembershipFrozen::for($freeze)));
    }

    public function handleCheckInDenied(CheckInDenied $event): void
    {
        $checkIn = $event->checkIn;

        $this->atTheGym($checkIn->organization_id, fn () => $checkIn->member?->notify(VisitDenied::for($checkIn)));
    }

    private function atTheGym(int $organizationId, Closure $tell): void
    {
        $this->currentOrganization->run(Organization::query()->findOrFail($organizationId), $tell);
    }
}
