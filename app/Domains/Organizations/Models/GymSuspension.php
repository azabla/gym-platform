<?php

declare(strict_types=1);

namespace App\Domains\Organizations\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Enums\SuspensionCause;
use App\Domains\Organizations\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One time a gym was paused: by an admin, who is named, or by the platform
 * for an unpaid bill; when and why; and who resumed it, if anyone did (a
 * payment resumes a pause for an unpaid bill by itself). A gym has at most
 * one open pause. Written only by Organization. See ADRs 0043 and 0045.
 *
 * @property CarbonImmutable $suspended_at
 * @property SuspensionCause $cause
 * @property string $reason
 * @property CarbonImmutable|null $resumed_at
 * @property string|null $resume_note
 * @property-read User|null $suspendedBy
 * @property-read User|null $resumedBy
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
            'cause' => SuspensionCause::class,
            'resumed_at' => 'immutable_datetime',
        ];
    }
}
