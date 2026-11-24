<?php

declare(strict_types=1);

namespace App\Domains\Organizations\Models;

use App\Domains\Organizations\Hours\OpeningWindow;
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
     * These hours as a window; closing earlier than opening runs past
     * midnight (ADR 0037).
     */
    public function window(): OpeningWindow
    {
        return new OpeningWindow((string) $this->opens_at, (string) $this->closes_at);
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
