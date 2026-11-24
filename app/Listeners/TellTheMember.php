<?php

namespace App\Listeners;

use App\Domains\Attendance\Access\Visits;
use App\Domains\Attendance\Events\CheckInAllowed;
use App\Domains\Attendance\Events\CheckInDenied;
use App\Domains\Billing\Enums\PaymentType;
use App\Domains\Billing\Events\PaymentLinkSentToMember;
use App\Domains\Billing\Events\PaymentRecorded;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Events\MembershipFrozen as MembershipWasFrozen;
use App\Domains\Organizations\Events\BranchClosureScheduled;
use App\Domains\Organizations\Models\BranchClosure;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Notifications\BranchClosing;
use App\Notifications\MembershipFrozen;
use App\Notifications\PaymentLinkSent;
use App\Notifications\PaymentSucceeded;
use App\Notifications\VisitDenied;
use App\Notifications\VisitsRunningLow;
use Closure;
use Illuminate\Support\Facades\Notification;

/**
 * Tells members what happened to them at a gym (ADR 0012): a payment
 * recorded, a freeze, a check-in turned away, a visit pack running low (ADR
 * 0016), their branch closing soon (ADR 0037). The domains only announce events; this is where they become messages. Each runs at the event's gym,
 * because it may run after the request's gym is gone, such as for a
 * payment confirmed by Chapa. Laravel finds the handle methods by the event
 * each takes.
 */
final class TellTheMember
{
    /** How soon a closure must start for members to hear of it when it is added. */
    public const TELL_CLOSURES_DAYS_AHEAD = 14;

    public function __construct(
        private CurrentOrganization $currentOrganization,
        private Visits $visits,
    ) {}

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

        // A member who froze it in the app knows already (ADR 0026).
        if ($freeze->isFromTheApp()) {
            return;
        }

        $this->atTheGym($freeze->organization_id, fn () => $freeze->membership->member->notify(MembershipFrozen::for($freeze)));
    }

    /**
     * A member turned away at the door. Not for a scan the staff app made
     * offline and sent later: the member was told at the door, maybe hours
     * ago (ADR 0038).
     */
    public function handleCheckInDenied(CheckInDenied $event): void
    {
        $checkIn = $event->checkIn;

        if ($checkIn->offline) {
            return;
        }

        $this->atTheGym($checkIn->organization_id, fn () => $checkIn->member?->notify(VisitDenied::for($checkIn)));
    }

    /**
     * The check-in that leaves a visit pack with one visit left: the first
     * check-in of the day that brings it there, so the member hears once.
     */
    public function handleCheckInAllowed(CheckInAllowed $event): void
    {
        $checkIn = $event->checkIn;

        $this->atTheGym($checkIn->organization_id, function () use ($checkIn): void {
            $membership = $checkIn->membership;

            if ($membership?->visit_limit === null || $checkIn->member === null) {
                return;
            }

            $timezone = $membership->organization->timezone;
            $visitsLeft = $membership->visit_limit - $this->visits->usedOn($timezone, $membership)[$membership->id];

            if ($visitsLeft === 1 && $this->visits->isFirstOfItsDay($checkIn, $timezone)) {
                $checkIn->member->notify(VisitsRunningLow::for($membership));
            }
        });
    }

    /**
     * The desk asked for the open link to be texted to the member (ADR 0031).
     */
    public function handlePaymentLinkSentToMember(PaymentLinkSentToMember $event): void
    {
        $paymentLink = $event->paymentLink;

        $this->atTheGym($paymentLink->organization_id, fn () => $paymentLink->member->notify(PaymentLinkSent::for($paymentLink)));
    }

    /**
     * Members of a branch closed, or open other hours, within the next 14
     * days, hear of it when it is added (ADR 0037).
     */
    public function handleBranchClosureScheduled(BranchClosureScheduled $event): void
    {
        $this->atTheGym($event->organizationId, function () use ($event): void {
            $soon = Organization::query()->findOrFail($event->organizationId)->today()->addDays(self::TELL_CLOSURES_DAYS_AHEAD);

            $closures = BranchClosure::query()
                ->with(['branch', 'organization'])
                ->whereKey($event->closureIds)
                ->whereDate('starts_on', '<=', $soon->toDateString())
                ->get();

            foreach ($closures as $closure) {
                Notification::send(
                    Member::query()->homeAtAnyOf([(int) $closure->branch_id])->get(),
                    BranchClosing::for($closure),
                );
            }
        });
    }

    /**
     * @param  Closure(): void  $tell
     */
    private function atTheGym(int $organizationId, Closure $tell): void
    {
        $this->currentOrganization->run(Organization::query()->findOrFail($organizationId), $tell);
    }
}
