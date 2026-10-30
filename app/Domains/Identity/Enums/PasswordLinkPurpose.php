<?php

declare(strict_types=1);

namespace App\Domains\Identity\Enums;

/**
 * Why a password link was sent: to set a new staff member's first password,
 * or to reset a forgotten one. See ADR 0019.
 */
enum PasswordLinkPurpose: string
{
    case Invite = 'invite';
    case Reset = 'reset';

    /**
     * How long a link works: a week to accept an invite, an hour to reset.
     */
    public function minutesValid(): int
    {
        return match ($this) {
            self::Invite => 7 * 24 * 60,
            self::Reset => 60,
        };
    }
}
