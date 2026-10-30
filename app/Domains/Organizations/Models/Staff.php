<?php

declare(strict_types=1);

namespace App\Domains\Organizations\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Exceptions\StaffRefused;
use App\Domains\Organizations\Policies\StaffPolicy;
use App\Domains\Organizations\Tenancy\BelongsToOrganization;
use Database\Factories\StaffFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * A user's role at one organization (gym), covering either all of its
 * branches or only the branches they are assigned to. See ADR 0003.
 *
 * Staff who leave are archived (soft deleted), so what they recorded still
 * names them, and a gym always keeps at least one owner. See ADR 0019.
 */
#[Table('staff')]
#[Fillable(['role'])]
#[UseFactory(StaffFactory::class)]
#[UsePolicy(StaffPolicy::class)]
class Staff extends Model
{
    /** @use HasFactory<StaffFactory> */
    use BelongsToOrganization, HasFactory, SoftDeletes;

    /**
     * Add the user to the current gym's staff with the role, at the given
     * branches or at all of them when none are given. Someone archived
     * earlier gets their old record back, so their history stays theirs.
     *
     * @throws StaffRefused when the user already works at the gym
     */
    public static function add(User $user, StaffRole $role, Branch ...$onlyAtBranches): self
    {
        return DB::transaction(function () use ($user, $role, $onlyAtBranches): self {
            $staff = self::query()->withTrashed()->where('user_id', $user->getKey())->first();

            if ($staff !== null && $staff->getAttribute('deleted_at') === null) {
                throw StaffRefused::alreadyWorksHere();
            }

            $staff ??= new self;
            $staff->user()->associate($user);
            $staff->setAttribute('deleted_at', null);
            $staff->reassign($role, ...$onlyAtBranches);

            return $staff;
        });
    }

    /**
     * Give the staff member the role, at the given branches or at all of them
     * when none are given. Owners always work at all branches.
     *
     * @throws StaffRefused when it would demote the gym's last owner
     */
    public function reassign(StaffRole $role, Branch ...$onlyAtBranches): void
    {
        DB::transaction(function () use ($role, $onlyAtBranches): void {
            if ($role !== StaffRole::Owner) {
                $this->refuseToRemoveTheLastOwner();
            }

            $this->role = $role;

            if ($role === StaffRole::Owner || $onlyAtBranches === []) {
                $this->assignToAllBranches();
            } else {
                $this->assignToBranches(...$onlyAtBranches);
            }
        });
    }

    /**
     * Archive the staff member: they can no longer act at the gym, and what
     * they recorded still names them.
     *
     * @throws StaffRefused when they are the gym's last owner
     */
    public function archive(): void
    {
        DB::transaction(function (): void {
            $this->refuseToRemoveTheLastOwner();
            $this->delete();
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The branches this staff member is limited to. Ignored while they work at all branches.
     *
     * @return BelongsToMany<Branch, $this>
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class);
    }

    public function assignToAllBranches(): void
    {
        DB::transaction(function (): void {
            $this->branches()->detach();
            $this->forceFill(['works_at_all_branches' => true])->save();
        });
    }

    public function assignToBranches(Branch ...$branches): void
    {
        DB::transaction(function () use ($branches): void {
            $this->forceFill(['works_at_all_branches' => false])->save();
            $this->branches()->syncWithPivotValues(
                array_map(fn (Branch $branch): int => $branch->getKey(), $branches),
                ['organization_id' => $this->organization_id],
            );
        });
    }

    public function worksAt(Branch $branch): bool
    {
        if ($branch->organization_id !== $this->organization_id) {
            return false;
        }

        return $this->works_at_all_branches
            || $this->branches()->whereKey($branch->getKey())->exists();
    }

    /**
     * Every branch of the organization when the staff member works at all
     * branches, otherwise only the branches they are assigned to.
     *
     * @return Collection<int, Branch>
     */
    public function branchesWorkedAt(): Collection
    {
        if ($this->works_at_all_branches) {
            return $this->organization->branches()->orderBy('name')->get();
        }

        return $this->branches()->orderBy('name')->get();
    }

    /**
     * The ids of the branches the staff member is limited to, or null when
     * they work at all branches, for lists and figures that cover only those.
     *
     * @return list<int>|null
     */
    public function limitedToBranchIds(): ?array
    {
        return $this->works_at_all_branches ? null : array_values($this->branchesWorkedAt()->modelKeys());
    }

    /**
     * Whether every branch the other staff member works at is one this staff
     * member works at too, so they may manage them (ADR 0019).
     */
    public function covers(Staff $other): bool
    {
        if ($this->works_at_all_branches) {
            return true;
        }

        if ($other->works_at_all_branches) {
            return false;
        }

        return array_diff($other->limitedToBranchIds() ?? [], $this->limitedToBranchIds() ?? []) === [];
    }

    /**
     * Staff who work at any of the branches, those at all branches included;
     * every staff member when the branches are null (all of them).
     *
     * @param  Builder<self>  $query
     * @param  list<int>|null  $branchIds
     */
    #[Scope]
    protected function atAnyOfBranches(Builder $query, ?array $branchIds): void
    {
        if ($branchIds === null) {
            return;
        }

        $query->where(function (Builder $query) use ($branchIds): void {
            $query->where('works_at_all_branches', true)
                ->orWhereHas('branches', fn (Builder $branches) => $branches->whereKey($branchIds));
        });
    }

    /**
     * Refuse when the staff member is an owner and no other owner of the gym
     * would be left. The gym's owners are locked first, so two owners can't
     * remove each other at the same moment.
     */
    private function refuseToRemoveTheLastOwner(): void
    {
        if (! $this->exists || $this->getOriginal('role') !== StaffRole::Owner) {
            return;
        }

        $owners = static::query()
            ->acrossOrganizations()
            ->where('organization_id', $this->organization_id)
            ->where('role', StaffRole::Owner)
            ->lockForUpdate()
            ->pluck('id');

        if ($owners->count() <= 1) {
            throw StaffRefused::lastOwner();
        }
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => StaffRole::class,
            'works_at_all_branches' => 'boolean',
        ];
    }
}
