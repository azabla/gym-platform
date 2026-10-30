<?php

declare(strict_types=1);

namespace App\Domains\Memberships\Models;

use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\MembershipFreezeFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A pause in a membership from starts_on through ends_on, both included. While
 * it lasts the membership is frozen, and freezing it moved the membership's end
 * date later by the same number of days. Create and end freezes through
 * Membership::freeze() and Membership::endFreeze(). See ADR 0007.
 */
#[UseFactory(MembershipFreezeFactory::class)]
class MembershipFreeze extends Model
{
    /** @use HasFactory<MembershipFreezeFactory> */
    use BelongsToOrganization, HasFactory;

    /** @return BelongsTo<Membership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    /** @return BelongsTo<Staff, $this> */
    public function frozenBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'frozen_by_staff_id')->withTrashed();
    }

    /**
     * How many days the freeze lasts, both ends included.
     */
    public function days(): int
    {
        return (int) $this->starts_on->diffInDays($this->ends_on) + 1;
    }

    public function covers(CarbonImmutable $day): bool
    {
        return ! $day->isBefore($this->starts_on) && ! $day->isAfter($this->ends_on);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
        ];
    }
}
