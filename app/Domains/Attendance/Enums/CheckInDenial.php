<?php

declare(strict_types=1);

namespace App\Domains\Attendance\Enums;

/**
 * Why a check-in was denied, in the codes agreed with the members app (the
 * Volt contract), which shows its own text for each. See ADR 0011.
 */
enum CheckInDenial: string
{
    /** Not a GQ1 code, not a credential of this gym, or a code that doesn't match. */
    case CredentialInvalid = 'CREDENTIAL_INVALID';

    case CredentialRevoked = 'CREDENTIAL_REVOKED';

    /** The code is more than one 30-second step away from now. */
    case CodeExpired = 'CODE_EXPIRED';

    /** The code was scanned before: a screenshot. */
    case CodeAlreadyUsed = 'CODE_ALREADY_USED';

    /** The branch is closed now, by its opening hours (ADR 0016). */
    case OutsideHours = 'OUTSIDE_HOURS';

    /** No membership valid today, or only one that starts later. */
    case MembershipNone = 'MEMBERSHIP_NONE';

    case MembershipExpired = 'MEMBERSHIP_EXPIRED';

    case MembershipFrozen = 'MEMBERSHIP_FROZEN';

    case BranchNotAllowed = 'BRANCH_NOT_ALLOWED';

    /** A visit pack with every visit used, on a day the member hasn't come in yet (ADR 0016). */
    case VisitsUsedUp = 'VISITS_USED_UP';

    /** Let in less than 5 minutes ago, so the code isn't passed back out the door. */
    case AlreadyCheckedIn = 'ALREADY_CHECKED_IN';
}
