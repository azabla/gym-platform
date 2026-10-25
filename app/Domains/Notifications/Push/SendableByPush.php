<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Push;

/**
 * A notification that can go by push: its via() lists PushChannel, and it
 * gives the push in the language of each phone it goes to.
 */
interface SendableByPush
{
    public function toPush(object $notifiable, string $locale): PushMessage;
}
