<?php

namespace App\Listeners;

use App\Domains\PlatformBilling\Enums\GymBillReminder;
use App\Domains\PlatformBilling\Events\GymBillReminderDue;
use App\Domains\PlatformBilling\Events\GymInvoiceIssued;
use App\Domains\PlatformBilling\Events\GymInvoicePaid;
use App\Domains\PlatformBilling\Events\GymPausedForUnpaidBill;
use App\Notifications\GymBillDueToday;
use App\Notifications\GymBillIssued;
use App\Notifications\GymBillOverdue;
use App\Notifications\GymBillPaid;
use App\Notifications\GymPausedForUnpaidBill as GymPausedMessage;
use App\Notifications\GymPausesTomorrow;

/**
 * Tells a gym's owners about the platform's bill (ADR 0045): an invoice
 * issued, its reminders, the gym paused for it, and it paid. The domain only
 * announces events; this is where they become messages. Laravel finds the
 * handle methods by the event each takes.
 */
final class TellTheOwners
{
    public function handleGymInvoiceIssued(GymInvoiceIssued $event): void
    {
        GymBillIssued::for($event->invoice)->toOwnersOf($event->invoice);
    }

    public function handleGymBillReminderDue(GymBillReminderDue $event): void
    {
        $message = match ($event->reminder) {
            GymBillReminder::DueToday => GymBillDueToday::for($event->invoice),
            GymBillReminder::Overdue => GymBillOverdue::for($event->invoice),
            GymBillReminder::PausesTomorrow => GymPausesTomorrow::for($event->invoice),
        };

        $message->toOwnersOf($event->invoice);
    }

    public function handleGymPausedForUnpaidBill(GymPausedForUnpaidBill $event): void
    {
        GymPausedMessage::for($event->invoice)->toOwnersOf($event->invoice);
    }

    public function handleGymInvoicePaid(GymInvoicePaid $event): void
    {
        GymBillPaid::for($event->invoice, $event->reopenedTheGym)->toOwnersOf($event->invoice);
    }
}
