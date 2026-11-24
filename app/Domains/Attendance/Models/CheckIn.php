<?php

declare(strict_types=1);

namespace App\Domains\Attendance\Models;

use App\Domains\Attendance\Access\AccessEvaluator;
use App\Domains\Attendance\Credentials\Gq1;
use App\Domains\Attendance\Enums\CheckInDenial;
use App\Domains\Attendance\Enums\CheckInMethod;
use App\Domains\Attendance\Enums\CheckInResult;
use App\Domains\Attendance\Events\CheckInAllowed;
use App\Domains\Attendance\Events\CheckInDenied;
use App\Domains\Attendance\Policies\CheckInPolicy;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\CheckInFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
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

    /** How far in the future an offline scan may be dated, for a scanner clock a little ahead. */
    public const OFFLINE_CLOCK_TOLERANCE_MINUTES = 2;

    /** How old an offline scan may be when it is sent: a door list lasts 2 days. */
    public const OFFLINE_MAXIMUM_AGE_DAYS = 3;

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

        return self::admit(CheckInMethod::Qr, $credential->member, $branch, $scannedBy, $credential, $idempotencyKey, $scanned->step);
    }

    /**
     * Check the member in by hand at the desk, for example when they forgot
     * their phone. The same membership rules apply.
     */
    public static function atDesk(Member $member, Branch $branch, Staff $checkedInBy): self
    {
        return self::admit(CheckInMethod::Desk, $member, $branch, $checkedInBy, null, null);
    }

    /**
     * Record a scan the staff app made offline, at the time it happened, with
     * what the door decided (ADR 0038). The code must be a real GQ1 code of
     * this gym for that time, so a forged or made-up scan is refused rather
     * than recorded. The server then decides again for that moment, and flags
     * the check-in with its reason when it would have decided otherwise. A
     * retry with the same scan id changes nothing.
     *
     * @param  CheckInDenial|null  $doorDenial  why the door turned the member away, or null when it let them in
     * @return array{status: 'recorded'|'already_recorded'|'refused', check_in: self|null, message: string|null}
     */
    public static function recordOffline(
        string $scanId,
        string $payload,
        Branch $branch,
        Staff $uploadedBy,
        CarbonImmutable $scannedAt,
        ?CheckInDenial $doorDenial,
    ): array {
        $earlier = static::query()->where('idempotency_key', $scanId)->first();

        if ($earlier !== null) {
            return ['status' => 'already_recorded', 'check_in' => $earlier, 'message' => null];
        }

        $now = CarbonImmutable::now();

        if ($scannedAt->gt($now->addMinutes(self::OFFLINE_CLOCK_TOLERANCE_MINUTES))) {
            return self::refusedOffline('The scan is dated in the future. Check the scanner\'s clock.');
        }

        if ($scannedAt->lt($now->subDays(self::OFFLINE_MAXIMUM_AGE_DAYS))) {
            return self::refusedOffline('The scan is more than '.self::OFFLINE_MAXIMUM_AGE_DAYS.' days old.');
        }

        $scanned = Gq1::parse($payload);
        $credential = $scanned === null ? null : AccessCredential::query()->find(strtolower($scanned->credentialId));

        if ($scanned === null || $credential === null) {
            return self::refusedOffline('This isn\'t a QR code of this gym.');
        }

        if (abs($scanned->step - Gq1::stepAt($scannedAt->getTimestamp())) > 1 || ! hash_equals($credential->codeAt($scanned->step), $scanned->code)) {
            return self::refusedOffline('The code doesn\'t match the time of the scan.');
        }

        $member = $credential->member;
        $decision = app(AccessEvaluator::class)->evaluate($member, $branch, $scannedAt);
        $serverDenial = match (true) {
            $credential->revoked_at !== null && $credential->revoked_at->lte($scannedAt) => CheckInDenial::CredentialRevoked,
            static::query()->where('access_credential_id', $credential->id)->where('code_step', $scanned->step)->exists() => CheckInDenial::CodeAlreadyUsed,
            default => $decision->denial,
        };

        AccessCredential::query()
            ->whereKey($credential->id)
            ->where(fn ($query) => $query->whereNull('last_used_step')->orWhere('last_used_step', '<', $scanned->step))
            ->update(['last_used_step' => $scanned->step]);

        $checkIn = self::record(CheckInMethod::Qr, $member, $branch, $uploadedBy, $decision->membership, $doorDenial, $credential, $scanId, $scanned->step, [
            'checked_in_at' => $scannedAt->utc(),
            'offline' => true,
            'server_disagrees' => $serverDenial !== $doorDenial,
            'server_reason_code' => $serverDenial,
        ]);

        return ['status' => 'recorded', 'check_in' => $checkIn, 'message' => null];
    }

    /**
     * @return array{status: 'refused', check_in: null, message: string}
     */
    private static function refusedOffline(string $message): array
    {
        return ['status' => 'refused', 'check_in' => null, 'message' => $message];
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
        return $this->belongsTo(Staff::class, 'checked_in_by_staff_id')->withTrashed();
    }

    /**
     * Let the member in if the membership rules allow it, and record either way.
     */
    private static function admit(CheckInMethod $method, Member $member, Branch $branch, Staff $staff, ?AccessCredential $credential, ?string $idempotencyKey, ?int $codeStep = null): self
    {
        $decision = app(AccessEvaluator::class)->evaluate($member, $branch);

        return self::record($method, $member, $branch, $staff, $decision->membership, $decision->denial, $credential, $idempotencyKey, $codeStep);
    }

    /**
     * @param  array<string, mixed>  $attributes  more to keep, such as an offline scan's time
     */
    private static function record(CheckInMethod $method, ?Member $member, Branch $branch, Staff $staff, ?Membership $membership, ?CheckInDenial $denial, ?AccessCredential $credential, ?string $idempotencyKey, ?int $codeStep = null, array $attributes = []): self
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
        $checkIn->code_step = $codeStep;
        $checkIn->forceFill($attributes);
        $checkIn->save();

        if ($denial !== null && $member !== null) {
            CheckInDenied::dispatch($checkIn);
        }

        if ($denial === null && $member !== null) {
            CheckInAllowed::dispatch($checkIn);
        }

        return $checkIn;
    }

    /**
     * Check-ins made at any of the branches; every one when the branches are
     * null (every branch). See ADR 0025.
     *
     * @param  Builder<self>  $query
     * @param  list<int>|null  $branchIds
     */
    #[Scope]
    protected function madeAtAnyOf(Builder $query, ?array $branchIds): void
    {
        if ($branchIds !== null) {
            $query->whereIn('branch_id', $branchIds);
        }
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
            'offline' => 'boolean',
            'server_disagrees' => 'boolean',
            'server_reason_code' => CheckInDenial::class,
        ];
    }
}
