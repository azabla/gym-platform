<?php

declare(strict_types=1);

namespace App\Domains\Imports;

use App\Domains\Imports\Enums\MemberImportRowOutcome;
use App\Domains\Imports\Exceptions\RowRefused;
use App\Domains\Members\Enums\Gender;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Shared\Money\Money;
use App\Domains\Shared\PhoneNumber\PhoneNumber;
use Carbon\CarbonImmutable;

/**
 * One row of a member import, checked: ready to import with what it says,
 * or why not. See RowCheck and ADR 0044.
 */
final readonly class CheckedRow
{
    private function __construct(
        public MemberImportRowOutcome $outcome,
        public ?RowRefused $reason = null,
        public ?string $fullName = null,
        public ?PhoneNumber $phone = null,
        public ?Gender $gender = null,
        public ?CarbonImmutable $dateOfBirth = null,
        public ?Branch $homeBranch = null,
        public ?Plan $plan = null,
        public ?CarbonImmutable $startsOn = null,
        public ?CarbonImmutable $endsOn = null,
        public ?Money $amountPaid = null,
    ) {}

    public static function ready(string $fullName, PhoneNumber $phone, ?Gender $gender, ?CarbonImmutable $dateOfBirth, Branch $homeBranch, ?Plan $plan, ?CarbonImmutable $startsOn, ?CarbonImmutable $endsOn, ?Money $amountPaid): self
    {
        return new self(MemberImportRowOutcome::Ready, null, $fullName, $phone, $gender, $dateOfBirth, $homeBranch, $plan, $startsOn, $endsOn, $amountPaid);
    }

    public static function refused(RowRefused $reason): self
    {
        return new self($reason->isDuplicate ? MemberImportRowOutcome::Duplicate : MemberImportRowOutcome::Error, $reason);
    }

    public function isReady(): bool
    {
        return $this->outcome === MemberImportRowOutcome::Ready;
    }
}
