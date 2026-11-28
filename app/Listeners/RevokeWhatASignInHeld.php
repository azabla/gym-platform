<?php

namespace App\Listeners;

use App\Domains\Attendance\Enums\CredentialRevocation;
use App\Domains\Attendance\Models\AccessCredential;
use App\Domains\Identity\Events\SignInEnding;

/**
 * When a phone's sign-in ends (signed out, signed out from another device,
 * or expired), the QR credentials issued to that phone stop working too
 * (ADRs 0011 and 0046). Identity doesn't know about check-ins, so this is
 * where the two meet.
 */
final class RevokeWhatASignInHeld
{
    public function handleSignInEnding(SignInEnding $event): void
    {
        AccessCredential::query()
            ->acrossOrganizations()
            ->where('personal_access_token_id', $event->tokenId)
            ->active()
            ->get()
            ->each(fn (AccessCredential $credential) => $credential->revoke(CredentialRevocation::SignedOut));
    }
}
