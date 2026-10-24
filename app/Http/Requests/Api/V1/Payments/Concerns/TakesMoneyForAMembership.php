<?php

namespace App\Http\Requests\Api\V1\Payments\Concerns;

use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Closure;

/**
 * The amount and branch rules shared by every request that takes money for
 * the membership in the URL: a payment, a refund and a payment link, so they
 * all say the same things.
 */
trait TakesMoneyForAMembership
{
    public function branch(): Branch
    {
        return Branch::query()->findOrFail($this->integer('branch_id'));
    }

    protected function membership(): Membership
    {
        return $this->route('membership');
    }

    protected function today(): CarbonImmutable
    {
        return app(CurrentOrganization::class)->get()->today();
    }

    protected function staff(): Staff
    {
        return app(CurrentStaff::class)->get();
    }

    /**
     * Staff who work at only one branch take money there unless they say otherwise.
     */
    protected function useTheOnlyBranchByDefault(): void
    {
        $branchesWorkedAt = $this->staff()->branchesWorkedAt();

        if (! $this->filled('branch_id') && $branchesWorkedAt->count() === 1) {
            $this->merge(['branch_id' => $branchesWorkedAt->sole()->id]);
        }
    }

    protected function moreThanZero(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (Money::of((string) $value)->isZero()) {
                $fail('The amount must be more than zero.');
            }
        };
    }

    /**
     * The branch must be a branch of this gym that the staff member works at.
     */
    protected function branchTheStaffMemberWorksAt(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $branch = is_numeric($value) ? Branch::query()->find((int) $value) : null;

            if ($branch === null || ! $this->staff()->worksAt($branch)) {
                $fail('Choose a branch of this gym that you work at.');
            }
        };
    }
}
