<?php

declare(strict_types=1);

namespace App\Domains\Memberships\Models;

use App\Casts\AsMoney;
use App\Domains\Memberships\Enums\DurationUnit;
use App\Domains\Memberships\Policies\PlanPolicy;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Tenancy\BelongsToOrganization;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * Something a gym sells: a price and a length in days or months, such as
 * "Monthly, 2,500 ETB". Archiving a plan (a soft delete) stops new sales, while
 * memberships already sold keep their own dates and price. A plan is valid at
 * every branch of its gym, or only at chosen ones. A visit pack also limits
 * how many days the member can come in. See ADR 0006, ADR 0007 and ADR 0016.
 *
 * @property Money $price Stored in the price_minor and currency columns.
 */
#[Fillable(['name', 'description', 'price', 'duration_count', 'duration_unit', 'freeze_days_allowed', 'visit_limit'])]
#[UseFactory(PlanFactory::class)]
#[UsePolicy(PlanPolicy::class)]
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use BelongsToOrganization, HasFactory, SoftDeletes;

    /** The longest plan counted in months. */
    public const MOST_MONTHS = 36;

    /** The longest plan counted in days. */
    public const MOST_DAYS = 366;

    /** The most freeze days a plan can allow. */
    public const MOST_FREEZE_DAYS = 366;

    /** The most visits a visit pack can allow. */
    public const MOST_VISITS = 1000;

    /**
     * The same defaults as the database columns, so a new plan knows them before it is saved.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'available_at_all_branches' => true,
        'freeze_days_allowed' => 0,
    ];

    /**
     * The branches this plan is limited to. Ignored while it is available at all branches.
     *
     * @return BelongsToMany<Branch, $this>
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class);
    }

    /**
     * Plans on sale that are valid at the branch: those valid at every branch,
     * and branch-only plans that include it. Archived plans aren't on sale.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function onSaleAt(Builder $query, int $branchId): void
    {
        $query->where(fn ($query) => $query
            ->where('available_at_all_branches', true)
            ->orWhereHas('branches', fn ($branches) => $branches->whereKey($branchId)));
    }

    /**
     * The gym's plan that already has the name, ignoring capitals, if any.
     * Plan names are unique within a gym; archived plans free theirs.
     */
    public static function nameTakenBy(string $name, ?self $ignoring = null): ?self
    {
        return static::query()
            ->whereRaw('lower(name) = lower(?)', [$name])
            ->when($ignoring !== null, fn (Builder $query) => $query->whereKeyNot($ignoring?->getKey()))
            ->first();
    }

    /**
     * The longest duration the unit allows.
     */
    public static function longestDurationIn(DurationUnit $unit): int
    {
        return $unit === DurationUnit::Month ? self::MOST_MONTHS : self::MOST_DAYS;
    }

    /**
     * The ids of the branches the plan is limited to, or null when it is
     * valid at every branch.
     *
     * @return list<int>|null
     */
    public function limitedToBranchIds(): ?array
    {
        return $this->available_at_all_branches ? null : array_map('intval', $this->branches()->pluck('branches.id')->all());
    }

    public function makeAvailableAtAllBranches(): void
    {
        DB::transaction(function (): void {
            $this->branches()->detach();
            $this->forceFill(['available_at_all_branches' => true])->save();
        });
    }

    public function makeAvailableOnlyAt(Branch ...$branches): void
    {
        DB::transaction(function () use ($branches): void {
            $this->forceFill(['available_at_all_branches' => false])->save();
            $this->branches()->syncWithPivotValues(
                array_map(fn (Branch $branch): int => $branch->getKey(), $branches),
                ['organization_id' => $this->organization_id],
            );
        });
    }

    /**
     * The last day of a membership of this plan that starts on the given day.
     * Both days are included: a 1-month plan starting 15 March runs through
     * 14 April, and a 30-day plan starting 1 March runs through 30 March. When
     * the next month is too short for the start day, the membership runs
     * through that month's last day: starting 31 January, through 28 February.
     */
    public function endsOnFor(CarbonImmutable $startsOn): CarbonImmutable
    {
        if ($this->duration_unit === DurationUnit::Day) {
            return $startsOn->addDays($this->duration_count - 1);
        }

        $sameDayLater = $startsOn->addMonthsNoOverflow($this->duration_count);

        return $sameDayLater->day === $startsOn->day ? $sameDayLater->subDay() : $sameDayLater;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => AsMoney::class.':price_minor,currency',
            'duration_count' => 'integer',
            'duration_unit' => DurationUnit::class,
            'available_at_all_branches' => 'boolean',
            'freeze_days_allowed' => 'integer',
            'visit_limit' => 'integer',
        ];
    }
}
