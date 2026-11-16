<?php

declare(strict_types=1);

namespace App\Domains\Billing\Policies;

use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use Illuminate\Auth\Access\Response;

/**
 * The staff who take payments (owners, managers, accountants and
 * receptionists) create, see, check, cancel and text payment links. Only
 * owners, managers and accountants mark a refund made. Trainers don't handle
 * money. See ADR 0009 and ADR 0031.
 */
final class PaymentLinkPolicy
{
    private const ROLES_THAT_TAKE_PAYMENTS = [
        StaffRole::Owner,
        StaffRole::Manager,
        StaffRole::Accountant,
        StaffRole::Receptionist,
    ];

    private const ROLES_THAT_SEE_MONEY = [
        StaffRole::Owner,
        StaffRole::Manager,
        StaffRole::Accountant,
    ];

    public function __construct(private CurrentStaff $currentStaff) {}

    public function viewAny(User $user): bool
    {
        return $this->takesPayments($this->staffFor($user));
    }

    public function view(User $user, PaymentLink $paymentLink): Response
    {
        return $this->atTheirBranch($this->staffFor($user, $paymentLink), $paymentLink);
    }

    public function create(User $user): bool
    {
        return $this->takesPayments($this->staffFor($user));
    }

    public function cancel(User $user, PaymentLink $paymentLink): Response
    {
        return $this->atTheirBranch($this->staffFor($user, $paymentLink), $paymentLink);
    }

    /**
     * Ask Chapa now whether the member has paid the link.
     */
    public function check(User $user, PaymentLink $paymentLink): Response
    {
        return $this->atTheirBranch($this->staffFor($user, $paymentLink), $paymentLink);
    }

    /**
     * Text the open link to the member (ADR 0031).
     */
    public function send(User $user, PaymentLink $paymentLink): Response
    {
        return $this->atTheirBranch($this->staffFor($user, $paymentLink), $paymentLink);
    }

    /**
     * Mark that the gym gave the member's money back: owners, managers and
     * accountants, who see the money (ADR 0031).
     */
    public function markRefunded(User $user, PaymentLink $paymentLink): Response
    {
        return $this->atTheirBranch($this->staffFor($user, $paymentLink), $paymentLink, self::ROLES_THAT_SEE_MONEY);
    }

    /**
     * Staff limited to some branches don't see links of other branches: for
     * them such a link isn't there, a 404 (ADR 0025). At their branches, the
     * staff member's role must be one of the roles given.
     *
     * @param  list<StaffRole>  $roles
     */
    private function atTheirBranch(?Staff $staff, PaymentLink $paymentLink, array $roles = self::ROLES_THAT_TAKE_PAYMENTS): Response
    {
        if ($staff === null || ! $this->takesPayments($staff)) {
            return Response::deny();
        }

        if (! $staff->worksAt($paymentLink->branch)) {
            return Response::denyAsNotFound();
        }

        return in_array($staff->role, $roles, true) ? Response::allow() : Response::deny();
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
