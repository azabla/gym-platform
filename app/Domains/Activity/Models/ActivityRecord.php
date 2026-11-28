<?php

declare(strict_types=1);

namespace App\Domains\Activity\Models;

use App\Domains\Activity\Enums\ActivityAction;
use App\Domains\Activity\Policies\ActivityRecordPolicy;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * One thing done with a gym's money, by whom, where and when, and what
 * changed (ADR 0046). Written only by Activity::record(), in the same
 * transaction as the action, and never changed or deleted.
 *
 * @property int|null $branch_id
 * @property int|null $staff_id
 * @property ActivityAction $action
 * @property string $subject_type
 * @property int $subject_id
 * @property array<string, scalar|null> $details
 * @property bool $flagged
 * @property CarbonImmutable $occurred_at
 * @property-read Staff|null $staff
 * @property-read Branch|null $branch
 */
#[UsePolicy(ActivityRecordPolicy::class)]
class ActivityRecord extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Activity records are never changed.'));
        static::deleting(fn (): never => throw new LogicException('Activity records are never deleted.'));
    }

    /**
     * What was done to one record, newest first.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function about(Builder $query, Model $subject): void
    {
        $query->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->latest('occurred_at')
            ->latest('id');
    }

    /**
     * What a staff member limited to some branches may see: those branches'
     * records and the gym-wide ones, such as plan prices.
     *
     * @param  Builder<self>  $query
     * @param  list<int>|null  $branchIds  null for every branch
     */
    #[Scope]
    protected function seenAtBranches(Builder $query, ?array $branchIds): void
    {
        if ($branchIds !== null) {
            $query->where(fn (Builder $query) => $query->whereNull('branch_id')->orWhereIn('branch_id', $branchIds));
        }
    }

    /** @return BelongsTo<Staff, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class)->withTrashed();
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'staff_id' => 'integer',
            'action' => ActivityAction::class,
            'subject_id' => 'integer',
            'details' => 'array',
            'flagged' => 'boolean',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
