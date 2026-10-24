<?php

declare(strict_types=1);

namespace App\Domains\Attendance\Models;

use App\Domains\Attendance\Credentials\Gq1;
use App\Domains\Attendance\Enums\CredentialRevocation;
use App\Domains\Identity\Models\User;
use App\Domains\Members\Models\Member;
use App\Domains\Organizations\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\AccessCredentialFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * The QR credential a member's phone shows at the door of one gym. The phone
 * gets the secret once and makes a new GQ1 code every 30 seconds from it,
 * offline. The secret is kept encrypted with the app key, because the server
 * makes the same codes to check a scan. A member has one active credential:
 * issuing one on another phone revokes the old one. See ADR 0011.
 */
#[UseFactory(AccessCredentialFactory::class)]
class AccessCredential extends Model
{
    /** @use HasFactory<AccessCredentialFactory> */
    use BelongsToOrganization, HasFactory, HasUlids;

    public const ALGORITHM = 'GQ1';

    /**
     * Never in arrays or JSON, even by accident.
     *
     * @var list<string>
     */
    protected $hidden = ['secret'];

    /**
     * Issue a credential to the member, for the phone signed in with the
     * user's token, and revoke the one they had. The member's row is locked
     * first, so two phones issuing at once still leave one active credential.
     *
     * @return array{0: self, 1: string} the credential, and its raw 32-byte secret for the phone
     */
    public static function issue(Member $member, User $user, ?int $personalAccessTokenId): array
    {
        return DB::transaction(function () use ($member, $user, $personalAccessTokenId): array {
            Member::query()->whereKey($member->id)->lockForUpdate()->firstOrFail();

            static::query()->whereBelongsTo($member)->active()->get()
                ->each(fn (self $credential) => $credential->revoke(CredentialRevocation::Replaced));

            $secret = random_bytes(32);

            $credential = new self;
            $credential->organization()->associate($member->organization_id);
            $credential->member()->associate($member);
            $credential->user()->associate($user);
            $credential->personal_access_token_id = $personalAccessTokenId;
            $credential->secret = base64_encode($secret);
            $credential->algorithm = self::ALGORITHM;
            $credential->save();

            return [$credential, $secret];
        });
    }

    /**
     * Stop the credential working, for the reason given.
     */
    public function revoke(CredentialRevocation $reason): void
    {
        $this->revoked_at = CarbonImmutable::now();
        $this->revoked_reason = $reason;
        $this->save();
    }

    /**
     * The ULID as it appears in GQ1 codes: in uppercase.
     */
    public function publicId(): string
    {
        return strtoupper($this->id);
    }

    /**
     * The code the member's phone shows at the step.
     */
    public function codeAt(int $step): string
    {
        return Gq1::code($this->rawSecret(), $this->publicId(), $step);
    }

    /**
     * The raw 32-byte secret.
     */
    public function rawSecret(): string
    {
        return (string) base64_decode($this->secret, true);
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Credentials still working.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'last_used_step' => 'integer',
            'revoked_at' => 'immutable_datetime',
            'revoked_reason' => CredentialRevocation::class,
        ];
    }
}
