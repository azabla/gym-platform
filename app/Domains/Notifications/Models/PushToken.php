<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Models;

use App\Domains\Notifications\Enums\PushApp;
use Database\Factories\PushTokenFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A phone's push token (from Firebase), the language it wants messages in,
 * and which app it is for: the members app or the staff app (ADR 0035). There is one for each sign-in token, so one for each phone, and signing
 * out deletes it. A push token belongs to one phone's sign-in at a time. It
 * keeps the user's id, not a relation, because Notifications depends on no
 * other domain. See ADR 0012.
 */
#[UseFactory(PushTokenFactory::class)]
class PushToken extends Model
{
    /** @use HasFactory<PushTokenFactory> */
    use HasFactory;

    /**
     * Keep the push token for the phone signed in with the given sign-in
     * token, replacing the one it had. If the push token was another sign-in's,
     * for example someone else's on the same phone before, it moves here.
     */
    public static function register(int $userId, int $personalAccessTokenId, string $token, ?string $locale, PushApp $app = PushApp::Members): self
    {
        return DB::transaction(function () use ($userId, $personalAccessTokenId, $token, $locale, $app): self {
            static::query()
                ->where('token', $token)
                ->where('personal_access_token_id', '!=', $personalAccessTokenId)
                ->delete();

            $pushToken = static::query()->where('personal_access_token_id', $personalAccessTokenId)->first() ?? new self;
            $pushToken->personal_access_token_id = $personalAccessTokenId;
            $pushToken->user_id = $userId;
            $pushToken->token = $token;
            $pushToken->locale = $locale;
            $pushToken->app = $app;
            $pushToken->save();

            return $pushToken;
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'app' => PushApp::class,
        ];
    }
}
