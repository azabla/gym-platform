<?php

namespace App\Http\Requests\Api\V1\Payments\Concerns;

use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Shared\Money\Money;
use App\Http\Requests\Api\V1\Concerns\AtABranchTheStaffMemberWorksAt;
use Carbon\CarbonImmutable;
use Closure;

/**
 * The amount and branch rules shared by every request that takes money for
 * the membership in the URL: a payment, a refund and a payment link, so they
 * all say the same things.
 */
trait TakesMoneyForAMembership
{
    use AtABranchTheStaffMemberWorksAt;

    protected function membership(): Membership
    {
        return $this->route('membership');
    }

    protected function today(): CarbonImmutable
    {
        return app(CurrentOrganization::class)->get()->today();
    }

    protected function moreThanZero(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (Money::of((string) $value)->isZero()) {
                $fail('The amount must be more than zero.');
            }
        };
    }
}
