<?php

declare(strict_types=1);

namespace App\Domains\Organizations\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One time the platform paused a gym: who paused it, when and why, and who
 * resumed it. A gym has at most one open pause. Written only by
 * Organization::suspend() and resume(). See ADR 0043.
 *
 * @property CarbonImmutable $suspended_at
 * @property string $reason
 * @property CarbonImmutable|null $resumed_at
 * @property string|null $resume_note
 */
class GymSuspension extends Model
{
    use BelongsToOrganization;

    /** @return BelongsTo<User, $this> */
    public function suspendedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'suspended_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resumedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resumed_by_user_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'suspended_at' => 'immutable_datetime',
            'resumed_at' => 'immutable_datetime',
        ];
    }
}
