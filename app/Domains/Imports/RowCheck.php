<?php

declare(strict_types=1);

namespace App\Domains\Imports;

use App\Domains\Imports\Exceptions\RowRefused;
use App\Domains\Members\Enums\Gender;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Shared\Money\Exceptions\InvalidMoneyAmount;
use App\Domains\Shared\Money\Money;
use App\Domains\Shared\PhoneNumber\Exceptions\InvalidPhoneNumber;
use App\Domains\Shared\PhoneNumber\PhoneNumber;
use Carbon\CarbonImmutable;

/**
 * Checks the rows of one member import file against the current gym: its
 * branches, its plans on sale and the members it has (archived ones too),
 * each looked up once for the file. Rows are checked in order, so a phone
 * that appears twice is a duplicate the second time. See ADR 0044.
 */
final class RowCheck
{
    /** @var array<string, Branch> Branches by lowercase name and code. */
    private array $branches = [];

    /** @var array<string, Plan> Plans on sale by lowercase name. */
    private array $plans = [];

    /** @var array<string, array{name: string, archived: bool}> The gym's members by phone. */
    private array $members = [];

    /** @var array<string, int> Phones seen so far in the file, with their line. */
    private array $seen = [];

    public function __construct(private readonly CarbonImmutable $today)
    {
        foreach (Branch::query()->get() as $branch) {
            $this->branches[mb_strtolower(trim((string) $branch->name))] = $branch;

            if (filled($branch->code)) {
                $this->branches[mb_strtolower(trim((string) $branch->code))] = $branch;
            }
        }

        foreach (Plan::query()->with('branches')->get() as $plan) {
            $this->plans[mb_strtolower(trim((string) $plan->name))] = $plan;
        }

        foreach (Member::query()->withTrashed()->get(['phone', 'full_name', 'deleted_at']) as $member) {
            $this->members[(string) $member->phone] = ['name' => (string) $member->full_name, 'archived' => $member->getAttribute('deleted_at') !== null];
        }
    }

    /**
     * @param  array<string, string>  $cells
     */
    public function check(int $line, array $cells): CheckedRow
    {
        try {
            return $this->ready($line, $cells);
        } catch (RowRefused $refusal) {
            return CheckedRow::refused($refusal);
        }
    }

    /**
     * @param  array<string, string>  $cells
     *
     * @throws RowRefused
     */
    private function ready(int $line, array $cells): CheckedRow
    {
        $fullName = $this->required($cells, 'full_name');
        $phone = $this->phone($this->required($cells, 'phone'));
        $this->notADuplicate($line, $phone);

        $branch = $this->branches[mb_strtolower($this->required($cells, 'home_branch'))]
            ?? throw RowRefused::unknownBranch($cells['home_branch']);

        $gender = $this->gender($cells['gender'] ?? '');
        $dateOfBirth = $this->date($cells, 'date_of_birth');

        [$plan, $startsOn, $endsOn, $amountPaid] = $this->membership($cells, $branch);

        return CheckedRow::ready($fullName, $phone, $gender, $dateOfBirth, $branch, $plan, $startsOn, $endsOn, $amountPaid);
    }

    /**
     * The membership the row brings, if any: its plan, sold at the member's
     * branch, its days and what was paid.
     *
     * @param  array<string, string>  $cells
     * @return array{Plan|null, CarbonImmutable|null, CarbonImmutable|null, Money|null}
     *
     * @throws RowRefused
     */
    private function membership(array $cells, Branch $branch): array
    {
        $planName = $cells['plan'] ?? '';
        $startsOn = $this->date($cells, 'starts_on');
        $endsOn = $this->date($cells, 'ends_on');
        $amountPaid = $this->amount($cells['amount_paid'] ?? '');

        if ($planName === '') {
            if ($startsOn !== null || $endsOn !== null || $amountPaid !== null) {
                throw RowRefused::membershipWithoutPlan();
            }

            return [null, null, null, null];
        }

        $plan = $this->plans[mb_strtolower($planName)] ?? throw RowRefused::unknownPlan($planName);
        $soldAt = $plan->available_at_all_branches || $plan->branches->contains('id', $branch->getKey());

        if (! $soldAt) {
            throw RowRefused::planNotSoldAt((string) $plan->name, (string) $branch->name);
        }

        if ($startsOn === null) {
            throw RowRefused::membershipWithoutStart();
        }

        if ($endsOn !== null && $endsOn->lessThan($startsOn)) {
            throw RowRefused::endsBeforeItStarts();
        }

        if ($amountPaid !== null && $amountPaid->isGreaterThan($plan->price)) {
            throw RowRefused::moreThanThePrice($amountPaid->format(), $plan->price->format());
        }

        return [$plan, $startsOn, $endsOn, $amountPaid];
    }

    /**
     * Whether the member can have the membership now, at import: no other
     * membership of theirs may overlap it.
     *
     * @throws RowRefused
     */
    public function stillFits(Member $member, CheckedRow $row): void
    {
        if ($row->plan === null || $row->startsOn === null) {
            return;
        }

        $refusal = Membership::refusalToBringIn($member, $row->plan, $row->startsOn, $row->endsOn, $this->today);

        if ($refusal !== null) {
            throw RowRefused::refused($refusal->getMessage());
        }
    }

    /**
     * @throws RowRefused
     */
    private function notADuplicate(int $line, PhoneNumber $phone): void
    {
        $member = $this->members[$phone->e164] ?? null;

        if ($member !== null) {
            throw $member['archived'] ? RowRefused::archivedMember($member['name']) : RowRefused::alreadyAMember($member['name']);
        }

        if (isset($this->seen[$phone->e164])) {
            throw RowRefused::sameAsLine($this->seen[$phone->e164]);
        }

        $this->seen[$phone->e164] = $line;
    }

    /**
     * @param  array<string, string>  $cells
     *
     * @throws RowRefused
     */
    private function required(array $cells, string $column): string
    {
        $value = trim($cells[$column] ?? '');

        return $value === '' ? throw RowRefused::missing($column) : $value;
    }

    /**
     * @throws RowRefused
     */
    private function phone(string $typed): PhoneNumber
    {
        try {
            return PhoneNumber::of($typed);
        } catch (InvalidPhoneNumber) {
            throw RowRefused::badPhone($typed);
        }
    }

    /**
     * @throws RowRefused
     */
    private function gender(string $typed): ?Gender
    {
        return match (mb_strtolower(trim($typed))) {
            '' => null,
            'female', 'f', 'ሴት' => Gender::Female,
            'male', 'm', 'ወንድ' => Gender::Male,
            default => throw RowRefused::badGender($typed),
        };
    }

    /**
     * A date written 2026-11-27 or 27/11/2026, as spreadsheets save them.
     *
     * @param  array<string, string>  $cells
     *
     * @throws RowRefused
     */
    private function date(array $cells, string $column): ?CarbonImmutable
    {
        $typed = trim($cells[$column] ?? '');

        if ($typed === '') {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $typed, $parts) === 1) {
            [, $year, $month, $day] = $parts;
        } elseif (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $typed, $parts) === 1) {
            [, $day, $month, $year] = $parts;
        } else {
            throw RowRefused::badDate($column, $typed);
        }

        if (! checkdate((int) $month, (int) $day, (int) $year)) {
            throw RowRefused::badDate($column, $typed);
        }

        return CarbonImmutable::create((int) $year, (int) $month, (int) $day);
    }

    /**
     * @throws RowRefused
     */
    private function amount(string $typed): ?Money
    {
        $typed = trim($typed);

        if ($typed === '') {
            return null;
        }

        try {
            $amount = Money::of($typed);
        } catch (InvalidMoneyAmount) {
            throw RowRefused::badAmount($typed);
        }

        return $amount->isZero() ? null : $amount;
    }
}
