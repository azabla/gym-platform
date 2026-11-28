<?php

declare(strict_types=1);

namespace App\Domains\Organizations\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Notifications\Enums\PushApp;
use App\Domains\Notifications\Enums\StaffNotificationTopic;
use App\Domains\Notifications\Models\PushToken;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Exceptions\StaffRefused;
use App\Domains\Organizations\Policies\StaffPolicy;
use App\Domains\Organizations\Tenancy\BelongsToOrganization;
use App\Domains\Shared\PhoneNumber\PhoneNumber;
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
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

/**
 * A user's role at one organization (gym), covering either all of its
 * branches or only the branches they are assigned to. See ADR 0003.
 *
 * Staff who leave are archived (soft deleted), so what they recorded still
 * names them, and a gym always keeps at least one owner. See ADR 0019.
 *
 * Each staff record has its own inbox, so someone who works at two gyms reads
 * each gym's messages there. See ADR 0027.
 */
#[Table('staff')]
#[Fillable(['role'])]
#[UseFactory(StaffFactory::class)]
#[UsePolicy(StaffPolicy::class)]
class Staff extends Model
{
    /** @use HasFactory<StaffFactory> */
    use BelongsToOrganization, HasFactory, Notifiable, SoftDeletes;

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
     * Whether messages about the topic also come by push: on until the staff
     * member turns it off. See ADR 0035.
     */
    public function wantsPushesAbout(StaffNotificationTopic $topic): bool
    {
        return ($this->notification_preferences[$topic->value]['push'] ?? true) === true;
    }

    /**
     * Whether messages about the topic also come by SMS: off until the staff
     * member turns it on. See ADR 0035.
     */
    public function wantsTextsAbout(StaffNotificationTopic $topic): bool
    {
        return ($this->notification_preferences[$topic->value]['sms'] ?? false) === true;
    }

    /**
     * Turn push or SMS on or off for some topics, keeping the rest.
     *
     * @param  array<string, array{push?: bool, sms?: bool}>  $choices  by topic, such as ["refunds" => ["sms" => true]]
     */
    public function chooseNotifications(array $choices): void
    {
        $preferences = $this->notification_preferences ?? [];

        foreach ($choices as $topic => $channels) {
            $preferences[$topic] = [...($preferences[$topic] ?? []), ...$channels];
        }

        $this->notification_preferences = $preferences;
        $this->save();
    }

    /**
     * Whether each topic comes by push and by SMS, by topic.
     *
     * @return array<string, array{push: bool, sms: bool}>
     */
    public function notificationChoices(): array
    {
        $choices = [];

        foreach (StaffNotificationTopic::chosenByStaff() as $topic) {
            $choices[$topic->value] = ['push' => $this->wantsPushesAbout($topic), 'sms' => $this->wantsTextsAbout($topic)];
        }

        return $choices;
    }

    /**
     * Pushes go to every phone signed in to the staff app as this staff
     * member's user (ADR 0035).
     *
     * @return Collection<int, PushToken>
     */
    public function routeNotificationForPush(): Collection
    {
        return PushToken::query()
            ->where('user_id', $this->user_id)
            ->where('app', PushApp::Staff->value)
            ->get();
    }

    /**
     * SMS goes to the user's phone, if they have one.
     */
    public function routeNotificationForSms(): ?PhoneNumber
    {
        $phone = $this->user?->phone;

        return $phone === null ? null : PhoneNumber::of($phone);
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
     * Whether the staff member works at every one of the branches; null
     * means every branch of the gym, which only staff at all branches cover.
     *
     * @param  list<int>|null  $branchIds
     */
    public function worksAtEach(?array $branchIds): bool
    {
        if ($this->works_at_all_branches) {
            return true;
        }

        return $branchIds !== null && array_diff($branchIds, $this->limitedToBranchIds() ?? []) === [];
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
     * Whether this staff member may give someone the role at the branches
     * (all of them when none are given): only a role they manage, and only
     * branches they work at themselves. Owners always work at all branches,
     * so the branches don't matter for them (ADR 0019).
     */
    public function mayAssign(StaffRole $role, Branch ...$onlyAtBranches): bool
    {
        if (! $role->isManagedBy($this->role)) {
            return false;
        }

        if ($role === StaffRole::Owner || $onlyAtBranches === []) {
            return $this->works_at_all_branches;
        }

        foreach ($onlyAtBranches as $branch) {
            if (! $this->worksAt($branch)) {
                return false;
            }
        }

        return true;
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
            'notification_preferences' => 'array',
        ];
    }
}
