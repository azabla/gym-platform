<?php

namespace App\Listeners;

use App\Domains\Billing\Models\ExpenseCategory;
use App\Domains\Organizations\Events\GymOpened;

/**
 * What a newly opened gym starts with in other domains: its expense
 * categories (ADR 0047). Organizations doesn't know about Billing, so this
 * is where they meet.
 */
final class StartTheGymsLists
{
    public function handleGymOpened(GymOpened $event): void
    {
        ExpenseCategory::startListFor($event->gym);
    }
}
