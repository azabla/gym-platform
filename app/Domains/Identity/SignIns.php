<?php

declare(strict_types=1);

namespace App\Domains\Identity;

use App\Domains\Identity\Enums\SignInApp;
use App\Domains\Identity\Events\SignInEnding;
use App\Domains\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The phones a person is signed in on, one API token each (ADR 0046): which
 * app each is for, when it was last used, signing one out, and letting one
 * unused for too long expire. Signing out announces SignInEnding first, so
 * what was issued under the sign-in is revoked with it.
 */
final class SignIns
{
    /**
     * Sign the user in on a phone, for an app.
     */
    public static function start(User $user, string $deviceName, SignInApp $app): NewAccessToken
    {
        $token = $user->createToken($deviceName);
        $token->accessToken->forceFill(['app' => $app->value])->save();

        return $token;
    }

    /**
     * Whether the sign-in was used recently enough to still work. One from
     * before sign-ins knew their app gets the staff app's shorter time.
     */
    public static function isFresh(PersonalAccessToken $token, ?CarbonImmutable $now = null): bool
    {
        $app = SignInApp::tryFrom((string) $token->getAttribute('app')) ?? SignInApp::Staff;
        $lastUsed = $token->last_used_at ?? $token->created_at;

        return $lastUsed !== null && $lastUsed->greaterThan(($now ?? CarbonImmutable::now())->subDays($app->daysUnused()));
    }

    /**
     * The user's sign-ins, the most recently used first.
     *
     * @return Collection<int, PersonalAccessToken>
     */
    public static function of(User $user): Collection
    {
        return $user->tokens()->orderByRaw('COALESCE(last_used_at, created_at) DESC')->get();
    }

    /**
     * End one sign-in: the phone has to sign in again.
     */
    public static function end(PersonalAccessToken $token): void
    {
        DB::transaction(function () use ($token): void {
            SignInEnding::dispatch((int) $token->getKey());
            $token->delete();
        });
    }

    /**
     * End every sign-in the user has for the app, such as when a staff
     * member's phone is lost.
     *
     * @return int how many were ended
     */
    public static function endEverywhere(User $user, SignInApp $app): int
    {
        $ended = 0;

        foreach ($user->tokens()->where('app', $app->value)->get() as $token) {
            self::end($token);
            $ended++;
        }

        return $ended;
    }

    /**
     * End the sign-ins unused for longer than their app allows.
     *
     * @return int how many were ended
     */
    public static function endUnused(): int
    {
        $ended = 0;

        PersonalAccessToken::query()->orderBy('id')->chunkById(500, function ($tokens) use (&$ended): void {
            foreach ($tokens as $token) {
                if (! self::isFresh($token)) {
                    self::end($token);
                    $ended++;
                }
            }
        });

        return $ended;
    }
}
