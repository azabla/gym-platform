<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use App\Domains\Identity\Enums\PasswordLinkPurpose;
use App\Domains\Identity\Notifications\PasswordLinkNotification;
use App\Domains\Identity\Notifications\StaffAddedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A link sent to a user by SMS to set their password: an invite when they
 * are added as staff, or a reset when they forgot it. It works once, until
 * it expires, and only the user's newest link works. The token is stored as
 * a hash keyed with the app key, so the database alone doesn't reveal a
 * link. See ADR 0019.
 */
class PasswordLink extends Model
{
    use Prunable;

    /**
     * Tell a user they were added to a gym's staff: with a link to set their
     * password if they have none yet, otherwise that they can sign in with
     * the one they have.
     */
    public static function invite(User $user, string $gymName): void
    {
        if ($user->password === null) {
            $user->notify(new PasswordLinkNotification(self::issue($user, PasswordLinkPurpose::Invite), PasswordLinkPurpose::Invite, $gymName));

            return;
        }

        $user->notify(new StaffAddedNotification($gymName));
    }

    /**
     * Send the user a link to reset their password.
     */
    public static function sendReset(User $user): void
    {
        $user->notify(new PasswordLinkNotification(self::issue($user, PasswordLinkPurpose::Reset), PasswordLinkPurpose::Reset));
    }

    /**
     * Create a new link for the user and give its token, to be sent. The
     * user's earlier links stop working.
     */
    public static function issue(User $user, PasswordLinkPurpose $purpose): string
    {
        $token = Str::random(48);

        DB::transaction(function () use ($user, $purpose, $token): void {
            static::query()->where('user_id', $user->getKey())->delete();

            $link = new self;
            $link->user_id = $user->getKey();
            $link->purpose = $purpose;
            $link->token_hash = self::hash($token);
            $link->expires_at = CarbonImmutable::now()->addMinutes($purpose->minutesValid());
            $link->save();
        });

        return $token;
    }

    /**
     * The link with the token, if it still works.
     */
    public static function findUsable(string $token): ?self
    {
        return static::query()
            ->where('token_hash', self::hash($token))
            ->whereNull('used_at')
            ->where('expires_at', '>', CarbonImmutable::now())
            ->with('user')
            ->first();
    }

    /**
     * Set the user's password and use up the link, and say whether it did:
     * a link used or expired meanwhile does nothing.
     */
    public function setPassword(string $password): bool
    {
        return DB::transaction(function () use ($password): bool {
            $link = static::query()->lockForUpdate()->find($this->getKey());

            if ($link === null || $link->used_at !== null || ! $link->expires_at->isFuture()) {
                return false;
            }

            $link->user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            $link->used_at = CarbonImmutable::now();
            $link->save();

            return true;
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Links are deleted by model:prune a day after they expire.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<', CarbonImmutable::now()->subDay());
    }

    private static function hash(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => PasswordLinkPurpose::class,
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
        ];
    }
}
