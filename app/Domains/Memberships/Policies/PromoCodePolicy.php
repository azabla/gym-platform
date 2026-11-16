<?php

declare(strict_types=1);

namespace App\Domains\Memberships\Policies;

use App\Domains\Identity\Models\User;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Memberships\Models\PromoCode;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentStaff;

/**
 * Owners and managers create and manage the gym's promo codes, as they are
 * the ones who may discount at the desk. A manager limited to some branches
 * manages only codes whose plans are all sold only at their branches; a code
 * for every plan needs staff at all branches, as with plans (ADR 0023). Any
 * staff member who sells may use a code. See ADR 0030.
 */
final class PromoCodePolicy
{
    private const ROLES_THAT_MANAGE_CODES = [
        StaffRole::Owner,
        StaffRole::Manager,
    ];

    public function __construct(private CurrentStaff $currentStaff) {}

    public function viewAny(User $user): bool
    {
        return $this->managesCodes($this->staffFor($user));
    }

    public function view(User $user, PromoCode $promoCode): bool
    {
        return $this->managesCodes($this->staffFor($user, $promoCode));
    }

    public function create(User $user): bool
    {
        return $this->managesCodes($this->staffFor($user));
    }

    /**
     * Make a code for these plans, or for every plan when null.
     *
     * @param  list<int>|null  $planIds
     */
    public function createFor(User $user, ?array $planIds): bool
    {
        return $this->managesCodesFor($this->staffFor($user), $planIds);
    }

    public function update(User $user, PromoCode $promoCode): bool
    {
        return $this->managesCodesFor($this->staffFor($user, $promoCode), $promoCode->limitedToPlanIds());
    }

    /**
     * Archive the code, so it can't be used any more.
     */
    public function delete(User $user, PromoCode $promoCode): bool
    {
        return $this->update($user, $promoCode);
    }

    public function restore(User $user, PromoCode $promoCode): bool
    {
        return $this->update($user, $promoCode);
    }

    /**
     * Whether the staff member manages codes for the plans (every plan when
     * null): each plan must be sold only at branches they work at.
     *
     * @param  list<int>|null  $planIds
     */
    private function managesCodesFor(?Staff $staff, ?array $planIds): bool
    {
        if (! $this->managesCodes($staff) || $staff === null) {
            return false;
        }

        if ($planIds === null) {
            return $staff->worksAtEach(null);
        }

        return Plan::query()->withTrashed()->whereKey($planIds)->get()
            ->every(fn (Plan $plan): bool => $staff->worksAtEach($plan->limitedToBranchIds()));
    }

    /**
     * The user's staff record in the current gym, if they are acting there
     * (and the code, when given, belongs to that gym).
     */
    private function staffFor(User $user, ?PromoCode $promoCode = null): ?Staff
    {
        $staff = $this->currentStaff->get();

        if (! $staff->user()->is($user)) {
            return null;
        }

        if ($promoCode !== null && $promoCode->organization_id !== $staff->organization_id) {
            return null;
        }

        return $staff;
    }

    private function managesCodes(?Staff $staff): bool
    {
        return $staff !== null && in_array($staff->role, self::ROLES_THAT_MANAGE_CODES, true);
    }
}
