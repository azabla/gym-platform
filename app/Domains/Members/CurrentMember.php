<?php

declare(strict_types=1);

namespace App\Domains\Members;

use App\Domains\Members\Exceptions\NoCurrentMember;
use App\Domains\Members\Models\Member;
use Illuminate\Container\Attributes\Scoped;

/**
 * The signed-in user's member record at the current gym: the one with the
 * user's phone number. It is set for the members app's gym routes, and every
 * query there is limited to it. Scoped, like CurrentStaff. See ADR 0010.
 */
#[Scoped]
final class CurrentMember
{
    private ?Member $member = null;

    public function set(Member $member): void
    {
        $this->member = $member;
    }

    public function get(): Member
    {
        return $this->member ?? throw NoCurrentMember::isSet();
    }
}
