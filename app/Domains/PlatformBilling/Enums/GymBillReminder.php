<?php

declare(strict_types=1);

namespace App\Domains\PlatformBilling\Enums;

/**
 * The reminders of an unpaid invoice a gym's owners get (ADR 0045): on its
 * due day, a week after it, and the day before it pauses the gym.
 */
enum GymBillReminder: string
{
    case DueToday = 'due_today';
    case Overdue = 'overdue';
    case PausesTomorrow = 'pauses_tomorrow';

    /** How many days after the due day the overdue reminder goes. */
    public const OVERDUE_AFTER_DAYS = 7;

    /**
     * The invoice column that records it was sent.
     */
    public function sentColumn(): string
    {
        return match ($this) {
            self::DueToday => 'due_reminder_sent_at',
            self::Overdue => 'overdue_reminder_sent_at',
            self::PausesTomorrow => 'pause_warning_sent_at',
        };
    }
}
