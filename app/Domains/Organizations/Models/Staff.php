<?php

declare(strict_types=1);

namespace App\Domains\Organizations\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Tenancy\BelongsToOrganization;
use Database\Factories\StaffFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

/**
 * A user's role at one organization (gym), covering either all of its
 * branches or only the branches they are assigned to. See ADR 0003.
 */
#[Table('staff')]
#[Fillable(['role'])]
#[UseFactory(StaffFactory::class)]
class Staff extends Model
{
    /** @use HasFactory<StaffFactory> */
    use BelongsToOrganization, HasFactory;

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
