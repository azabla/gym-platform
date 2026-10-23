<?php

declare(strict_types=1);

namespace App\Domains\Billing\Policies;

use App\Domains\Billing\Models\Payment;
use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentStaff;

/**
 * Owners, managers, accountants and receptionists take payments and may see
 * the gym's payments and receipts; trainers don't handle money. Only owners,
 * managers and accountants may date a payment in the past, so takings can't
 * be moved to another day once the drawer is counted. See ADR 0008.
 */
final class PaymentPolicy
{
    private const ROLES_THAT_TAKE_PAYMENTS = [
        StaffRole::Owner,
        StaffRole::Manager,
        StaffRole::Accountant,
        StaffRole::Receptionist,
    ];

    private const OWNERS_MANAGERS_AND_ACCOUNTANTS = [
        StaffRole::Owner,
        StaffRole::Manager,
        StaffRole::Accountant,
    ];

    public function __construct(private CurrentStaff $currentStaff) {}

    public function view(User $user, Payment $payment): bool
    {
        return $this->hasRole($this->staffFor($user, $payment), self::ROLES_THAT_TAKE_PAYMENTS);
    }

    public function create(User $user): bool
    {
        return $this->hasRole($this->staffFor($user), self::ROLES_THAT_TAKE_PAYMENTS);
    }

    /**
     * Date a payment before today, for example a bank transfer that arrived yesterday.
     */
    public function backdate(User $user): bool
    {
        return $this->hasRole($this->staffFor($user), self::OWNERS_MANAGERS_AND_ACCOUNTANTS);
    }

    /**
     * The user's staff record in the current gym, if they are acting there
     * (and the payment, when given, belongs to that gym).
     */
    private function staffFor(User $user, ?Payment $payment = null): ?Staff
    {
        $staff = $this->currentStaff->get();

        if (! $staff->user()->is($user)) {
            return null;
        }

        if ($payment !== null && $payment->organization_id !== $staff->organization_id) {
            return null;
        }

        return $staff;
    }

    /**
     * @param  list<StaffRole>  $roles
     */
    private function hasRole(?Staff $staff, array $roles): bool
    {
        return $staff !== null && in_array($staff->role, $roles, true);
    }
}
