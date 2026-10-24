<?php

declare(strict_types=1);

namespace App\Domains\Attendance\Models;

use App\Domains\Attendance\Access\AccessEvaluator;
use App\Domains\Attendance\Credentials\Gq1;
use App\Domains\Attendance\Enums\CheckInDenial;
use App\Domains\Attendance\Enums\CheckInMethod;
use App\Domains\Attendance\Enums\CheckInResult;
use App\Domains\Attendance\Policies\CheckInPolicy;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\CheckInFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member coming through a gym's door, allowed or denied: by scanning their
 * QR code or at the desk. Every attempt is kept, with its reason when
 * denied, so staff and the member can see what happened. See ADR 0011.
 */
#[UseFactory(CheckInFactory::class)]
#[UsePolicy(CheckInPolicy::class)]
class CheckIn extends Model
{
    /** @use HasFactory<CheckInFactory> */
    use BelongsToOrganization, HasFactory;

    /**
     * Check in whoever shows the scanned QR code at the branch. The code must
     * be a fresh GQ1 code of an active credential of this gym, never scanned
     * before, and then the membership rules decide (see AccessEvaluator). A
     * retry with the same idempotency key gets the first answer back.
     */
    public static function scan(string $payload, Branch $branch, Staff $scannedBy, ?string $idempotencyKey = null): self
    {
        $earlier = $idempotencyKey === null ? null : static::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($earlier !== null) {
            return $earlier;
        }

        $scanned = Gq1::parse($payload);
        $credential = $scanned === null ? null : AccessCredential::query()->find(strtolower($scanned->credentialId));
        $deny = fn (CheckInDenial $denial, ?Member $member = null): self => self::record(
            CheckInMethod::Qr, $member, $branch, $scannedBy, null, $denial, $credential, $idempotencyKey,
        );

        if ($scanned === null || $credential === null) {
            return $deny(CheckInDenial::CredentialInvalid);
        }

        if ($credential->revoked_at !== null) {
            return $deny(CheckInDenial::CredentialRevoked, $credential->member);
        }

        if (abs($scanned->step - Gq1::stepAt(CarbonImmutable::now()->getTimestamp())) > 1) {
            return $deny(CheckInDenial::CodeExpired, $credential->member);
        }

        if (! hash_equals($credential->codeAt($scanned->step), $scanned->code)) {
            return $deny(CheckInDenial::CredentialInvalid);
        }

        $firstUse = AccessCredential::query()
            ->whereKey($credential->id)
            ->where(fn ($query) => $query->whereNull('last_used_step')->orWhere('last_used_step', '<', $scanned->step))
            ->update(['last_used_step' => $scanned->step]);

        if ($firstUse === 0) {
            return $deny(CheckInDenial::CodeAlreadyUsed, $credential->member);
        }

        return self::admit(CheckInMethod::Qr, $credential->member, $branch, $scannedBy, $credential, $idempotencyKey);
    }

    /**
     * Check the member in by hand at the desk, for example when they forgot
     * their phone. The same membership rules apply.
     */
    public static function atDesk(Member $member, Branch $branch, Staff $checkedInBy): self
    {
        return self::admit(CheckInMethod::Desk, $member, $branch, $checkedInBy, null, null);
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * The membership the member came in with, or was turned away with.
     *
     * @return BelongsTo<Membership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    /** @return BelongsTo<Staff, $this> */
    public function checkedInBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'checked_in_by_staff_id');
    }

    /**
     * Let the member in if the membership rules allow it, and record either way.
     */
    private static function admit(CheckInMethod $method, Member $member, Branch $branch, Staff $staff, ?AccessCredential $credential, ?string $idempotencyKey): self
    {
        $decision = app(AccessEvaluator::class)->evaluate($member, $branch);

        return self::record($method, $member, $branch, $staff, $decision->membership, $decision->denial, $credential, $idempotencyKey);
    }

    private static function record(CheckInMethod $method, ?Member $member, Branch $branch, Staff $staff, ?Membership $membership, ?CheckInDenial $denial, ?AccessCredential $credential, ?string $idempotencyKey): self
    {
        $checkIn = new self;
        $checkIn->organization()->associate($branch->organization_id);
        $checkIn->member()->associate($member);
        $checkIn->branch()->associate($branch);
        $checkIn->membership()->associate($membership);
        $checkIn->access_credential_id = $credential?->id;
        $checkIn->method = $method;
        $checkIn->result = $denial === null ? CheckInResult::Allowed : CheckInResult::Denied;
        $checkIn->reason_code = $denial;
        $checkIn->checkedInBy()->associate($staff);
        $checkIn->checked_in_at = CarbonImmutable::now();
        $checkIn->idempotency_key = $idempotencyKey;
        $checkIn->save();

        return $checkIn;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => CheckInMethod::class,
            'result' => CheckInResult::class,
            'reason_code' => CheckInDenial::class,
            'checked_in_at' => 'immutable_datetime',
        ];
    }
}
