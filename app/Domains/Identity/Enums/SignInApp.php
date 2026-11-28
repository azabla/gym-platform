<?php

declare(strict_types=1);

namespace App\Domains\Identity\Enums;

/**
 * The app a phone is signed in to (ADR 0046): the staff app, signed in with
 * a password, or the members app, with an SMS code. Each has its own time a
 * sign-in may go unused before it expires.
 */
enum SignInApp: string
{
    case Staff = 'staff';
    case Members = 'members';

    /**
     * How many days a sign-in may go unused before it expires.
     */
    public function daysUnused(): int
    {
        return match ($this) {
            self::Staff => 30,
            self::Members => 90,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Staff => 'Staff app',
            self::Members => 'Members app',
        };
    }
}
