<?php

declare(strict_types=1);

namespace App\Domains\Organizations\Models;

use App\Domains\Organizations\Tenancy\BelongsToOrganization;
use Database\Factories\BranchOpeningHourFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * When a branch is open on one weekday (1 is Monday, 7 is Sunday), from
 * opens_at until closes_at, in the gym's time zone. Times are kept as
 * "HH:MM:SS". See ADR 0016.
 */
#[UseFactory(BranchOpeningHourFactory::class)]
class BranchOpeningHour extends Model
{
    /** @use HasFactory<BranchOpeningHourFactory> */
    use BelongsToOrganization, HasFactory;

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Whether the time of day, "HH:MM:SS", falls within these hours on their
     * own weekday: from opening until just before closing, or until midnight
     * when they run past it.
     */
    public function covers(string $timeOfDay): bool
    {
        return $timeOfDay >= $this->opens_at && ($this->runsPastMidnight() || $timeOfDay < $this->closes_at);
    }

    /**
     * Whether the time of day on the next day still falls within these
     * hours: before closing, when they run past midnight.
     */
    public function coversTheNextMorning(string $timeOfDay): bool
    {
        return $this->runsPastMidnight() && $timeOfDay < $this->closes_at;
    }

    /**
     * Whether the branch closes after midnight, on the next day: closing is
     * given as earlier than opening, such as 18:00–02:00 (ADR 0037).
     */
    public function runsPastMidnight(): bool
    {
        return $this->closes_at < $this->opens_at;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
        ];
    }
}
