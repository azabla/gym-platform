<?php

declare(strict_types=1);

namespace App\Filament\Staff\Actions;

use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Stops an action the domain refused, telling the staff member why in the
 * domain's own words, as the API does with a 422. See ADR 0018.
 */
final class Refusals
{
    public static function halt(Action $action, DomainException $refusal): never
    {
        Notification::make()
            ->danger()
            ->title($refusal->getMessage())
            ->send();

        $action->halt();

        throw $refusal;
    }
}
