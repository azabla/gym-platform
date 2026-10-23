<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use App\Domains\Shared\PhoneNumber\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Facades\DB;

/**
 * A code sent by SMS that lets the owner of a phone number sign in: six
 * digits, valid for ten minutes and five tries. Only the newest code for a
 * phone works, and only once. It is stored as a hash keyed with the app key,
 * so the database alone doesn't reveal a code. See ADR 0010.
 */
class SignInCode extends Model
{
    use Prunable;

    public const MINUTES_VALID = 10;

    public const TRIES = 5;

    /**
     * Create a new code for the phone and give it, to be sent. Earlier codes
     * for the phone stop working.
     */
    public static function issue(PhoneNumber $phone): string
    {
        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);

        $signInCode = new self;
        $signInCode->phone = $phone->e164;
        $signInCode->code_hash = self::hash($code);
        $signInCode->expires_at = CarbonImmutable::now()->addMinutes(self::MINUTES_VALID);
        $signInCode->save();

        return $code;
    }

    /**
     * Use up the phone's newest code if the code given matches it, and say
     * whether it did. Every try counts, so a code can't be guessed.
     */
    public static function redeem(PhoneNumber $phone, string $code): bool
    {
        return DB::transaction(function () use ($phone, $code): bool {
            $signInCode = static::query()
                ->where('phone', $phone->e164)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($signInCode === null || ! $signInCode->canBeTried()) {
                return false;
            }

            $signInCode->attempts++;
            $matches = hash_equals($signInCode->code_hash, self::hash($code));

            if ($matches) {
                $signInCode->used_at = CarbonImmutable::now();
            }

            $signInCode->save();

            return $matches;
        });
    }

    /**
     * Codes are deleted by model:prune a day after they expire.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<', CarbonImmutable::now()->subDay());
    }

    private function canBeTried(): bool
    {
        return $this->used_at === null
            && $this->expires_at->isFuture()
            && $this->attempts < self::TRIES;
    }

    private static function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
        ];
    }
}
