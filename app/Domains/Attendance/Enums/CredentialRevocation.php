<?php

declare(strict_types=1);

namespace App\Domains\Attendance\Enums;

/**
 * Why a QR credential stopped working. See ADR 0011.
 */
enum CredentialRevocation: string
{
    /** The member issued a credential on another phone: one phone per member. */
    case Replaced = 'replaced';

    /** The member signed out on the phone that held it. */
    case SignedOut = 'signed_out';
}
