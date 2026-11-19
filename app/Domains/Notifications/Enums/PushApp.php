<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Enums;

/**
 * Which app a phone's push token is for: messages to members go to the
 * members app, messages to staff to the staff app. See ADR 0035.
 */
enum PushApp: string
{
    case Members = 'members';
    case Staff = 'staff';
}
