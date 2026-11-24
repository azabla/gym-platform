<?php

declare(strict_types=1);

namespace App\Domains\Organizations\Models;

use App\Domains\Organizations\Events\BranchClosureScheduled;
use App\Domains\Organizations\Exceptions\BranchClosureRefused;
use App\Domains\Organizations\Hours\OpeningWindow;
use App\Domains\Organizations\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\BranchClosureFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Days a branch is closed, such as a holiday or repairs, from starts_on to
 * ends_on (both included), with a reason. It is closed all day, or open
 * other hours on those days: opens_at and closes_at, kept as "HH:MM:SS",
 * where closing earlier than opening means after midnight. It overrides the
 * weekly hours on its days. See ADR 0037.
 */
#[UseFactory(BranchClosureFactory::class)]
class BranchClosure extends Model
{
    /** @use HasFactory<BranchClosureFactory> */
    use BelongsToOrganization, HasFactory;

    /** The most days one closure may cover. */
    public const MAXIMUM_DAYS = 31;

    /** How far ahead the apps show a branch's closures, in days. */
    public const SHOWN_DAYS_AHEAD = 60;

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Staff, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'created_by_staff_id')->withTrashed();
    }

    /**
     * Why the staff member can't close the branches on those days, or null
     * when they can: a closure starts today at the earliest, lasts at most
     * 31 days, is only at branches the staff member works at, and never
     * overlaps another closure of the same branch.
     *
     * @param  list<Branch>  $branches
     */
    public static function refusalToSchedule(Staff $by, array $branches, CarbonImmutable $startsOn, CarbonImmutable $endsOn): ?BranchClosureRefused
    {
        $today = $by->organization->today();

        if ($startsOn->lt($today)) {
            return BranchClosureRefused::inThePast();
        }

        if ($startsOn->diffInDays($endsOn) + 1 > self::MAXIMUM_DAYS) {
            return BranchClosureRefused::tooLong(self::MAXIMUM_DAYS);
        }

        foreach ($branches as $branch) {
            if (! $by->worksAt($branch)) {
                return BranchClosureRefused::notAtYourBranch((string) $branch->name);
            }

            if (self::query()->where('branch_id', $branch->id)->overlapping($startsOn, $endsOn)->exists()) {
                return BranchClosureRefused::overlaps((string) $branch->name);
            }
        }

        return null;
    }

    /**
     * Close each branch on those days, all day or with other hours ("HH:MM"
     * or "HH:MM:SS"), one closure for each branch so each can be taken back
     * alone. Members of the branches hear of it (BranchClosureScheduled).
     *
     * @param  list<Branch>  $branches
     * @return Collection<int, self>
     *
     * @throws BranchClosureRefused
     */
    public static function schedule(
        Staff $by,
        array $branches,
        CarbonImmutable $startsOn,
        CarbonImmutable $endsOn,
        string $reason,
        ?string $opensAt = null,
        ?string $closesAt = null,
    ): Collection {
        $refusal = self::refusalToSchedule($by, $branches, $startsOn, $endsOn);

        if ($refusal !== null) {
            throw $refusal;
        }

        return DB::transaction(function () use ($by, $branches, $startsOn, $endsOn, $reason, $opensAt, $closesAt): Collection {
            $closures = new Collection;

            foreach ($branches as $branch) {
                $closure = new self;
                $closure->forceFill([
                    'organization_id' => $by->organization_id,
                    'branch_id' => $branch->id,
                    'starts_on' => $startsOn->toDateString(),
                    'ends_on' => $endsOn->toDateString(),
                    'reason' => trim($reason),
                    'opens_at' => self::timeOrNull($opensAt),
                    'closes_at' => self::timeOrNull($closesAt),
                    'created_by_staff_id' => $by->id,
                ])->save();
                $closures->push($closure);
            }

            BranchClosureScheduled::dispatch(
                (int) $by->organization_id,
                array_map(fn (self $closure): int => (int) $closure->id, $closures->all()),
            );

            return $closures;
        });
    }

    /**
     * Whether the branch is closed all day, rather than open other hours.
     */
    public function isClosedAllDay(): bool
    {
        return $this->opens_at === null;
    }

    /**
     * The hours the branch keeps on the closure's days, or null when it is
     * closed all day.
     */
    public function window(): ?OpeningWindow
    {
        return $this->isClosedAllDay() ? null : new OpeningWindow((string) $this->opens_at, (string) $this->closes_at);
    }

    /**
     * Whether the closure covers the date.
     */
    public function covers(CarbonImmutable $date): bool
    {
        $day = $date->toDateString();

        return $this->starts_on->toDateString() <= $day && $this->ends_on->toDateString() >= $day;
    }

    /**
     * Closures that share at least one day with the dates (both included).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function overlapping(Builder $query, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $query->whereDate('starts_on', '<=', $to->toDateString())->whereDate('ends_on', '>=', $from->toDateString());
    }

    /**
     * Closures that haven't ended by the date.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function notEndedBy(Builder $query, CarbonImmutable $date): void
    {
        $query->whereDate('ends_on', '>=', $date->toDateString());
    }

    /**
     * Closures going on or starting within the coming days, as the apps show
     * them with a branch.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function upcomingFrom(Builder $query, CarbonImmutable $today): void
    {
        $query->whereDate('ends_on', '>=', $today->toDateString())
            ->whereDate('starts_on', '<=', $today->addDays(self::SHOWN_DAYS_AHEAD)->toDateString());
    }

    private static function timeOrNull(?string $time): ?string
    {
        return $time === null || $time === '' ? null : substr($time, 0, 5).':00';
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
            'members_told_at' => 'immutable_datetime',
            'reminded_at' => 'immutable_datetime',
            'repeats_yearly' => 'boolean',
            'repeated_at' => 'immutable_datetime',
        ];
    }
}
