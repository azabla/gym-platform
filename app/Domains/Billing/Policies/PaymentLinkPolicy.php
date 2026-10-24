<?php

declare(strict_types=1);

namespace App\Domains\Billing\Policies;

use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentStaff;

/**
 * The staff who take payments (owners, managers, accountants and
 * receptionists) create, see, check and cancel payment links. Trainers don't
 * handle money. See ADR 0009.
 */
final class PaymentLinkPolicy
{
    private const ROLES_THAT_TAKE_PAYMENTS = [
        StaffRole::Owner,
        StaffRole::Manager,
        StaffRole::Accountant,
        StaffRole::Receptionist,
    ];

    public function __construct(private CurrentStaff $currentStaff) {}

    public function viewAny(User $user): bool
    {
        return $this->takesPayments($this->staffFor($user));
    }

    public function view(User $user, PaymentLink $paymentLink): bool
    {
        return $this->takesPayments($this->staffFor($user, $paymentLink));
    }

    public function create(User $user): bool
    {
        return $this->takesPayments($this->staffFor($user));
    }

    public function cancel(User $user, PaymentLink $paymentLink): bool
    {
        return $this->takesPayments($this->staffFor($user, $paymentLink));
    }

    /**
     * Ask Chapa now whether the member has paid the link.
     */
    public function check(User $user, PaymentLink $paymentLink): bool
    {
        return $this->takesPayments($this->staffFor($user, $paymentLink));
    }

    /**
     * The user's staff record in the current gym, if they are acting there
     * (and the link, when given, belongs to that gym).
     */
    private function staffFor(User $user, ?PaymentLink $paymentLink = null): ?Staff
    {
        $staff = $this->currentStaff->get();

        if (! $staff->user()->is($user)) {
            return null;
        }

        if ($paymentLink !== null && $paymentLink->organization_id !== $staff->organization_id) {
            return null;
        }

        return $staff;
    }

    private function takesPayments(?Staff $staff): bool
    {
        return $staff !== null && in_array($staff->role, self::ROLES_THAT_TAKE_PAYMENTS, true);
    }
}
