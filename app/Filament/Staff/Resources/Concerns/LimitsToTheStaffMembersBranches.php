<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\Concerns;

use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use Illuminate\Database\Eloquent\Builder;

/**
 * Staff limited to some branches see the records of those branches only, as
 * in the API (ADR 0025). Staff at all branches see the whole gym.
 */
trait LimitsToTheStaffMembersBranches
{
    /**
     * The branches the staff member works at, or null for every branch.
     *
     * @return list<int>|null
     */
    protected static function staffBranchIds(): ?array
    {
        return app(CurrentStaff::class)->get()->limitedToBranchIds();
    }

    /**
     * A branch filter offers only the staff member's branches.
     *
     * @param  Builder<Branch>  $query
     * @return Builder<Branch>
     */
    protected static function onlyTheirBranches(Builder $query): Builder
    {
        $branchIds = self::staffBranchIds();

        return $branchIds === null ? $query : $query->whereKey($branchIds);
    }
}
