<?php

declare(strict_types=1);

namespace App\Domains\Identity\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A phone's sign-in is about to end: signed out, signed out from another
 * device, or expired (ADR 0046). Dispatched at once, before the sign-in is
 * deleted, so what was issued under it (QR credentials) can be revoked.
 */
final class SignInEnding
{
    use Dispatchable;

    public function __construct(public readonly int $tokenId) {}
}
