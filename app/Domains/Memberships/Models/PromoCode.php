<?php

declare(strict_types=1);

namespace App\Domains\Memberships\Models;

use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Discount\Discount;
use App\Domains\Memberships\Discount\Exceptions\InvalidDiscount;
use App\Domains\Memberships\Exceptions\PromoCodeRefused;
use App\Domains\Memberships\Policies\PromoCodePolicy;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\BelongsToOrganization;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Database\Factories\PromoCodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * A code the gym shares, such as NEWYEAR, worth a discount on a plan when a
 * member buys it in the app or at the desk. The discount is kept as staff type
 * it ("20%" or "500"). A code may have first and last days (the gym's), a
 * total number of uses, a limit of one use per member, and a list of plans.
 * A use is a membership sold with the code. Archiving (a soft delete) stops
 * new uses. See ADR 0030.
 */
#[Fillable(['code', 'discount', 'starts_on', 'ends_on', 'max_uses', 'once_per_member'])]
#[UseFactory(PromoCodeFactory::class)]
#[UsePolicy(PromoCodePolicy::class)]
class PromoCode extends Model
{
    /** @use HasFactory<PromoCodeFactory> */
    use BelongsToOrganization, HasFactory, SoftDeletes;

    /** A code is 3 to 30 letters, digits or dashes. */
    public const PATTERN = '/^[A-Za-z0-9-]{3,30}$/';

    /**
     * The same defaults as the database columns, so a new code knows them before it is saved.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'once_per_member' => true,
        'available_for_all_plans' => true,
    ];

    /**
     * The gym's code as a member or staff member typed it, ignoring capitals
     * and spaces around it. Archived codes aren't found.
     */
    public static function findByCode(string $typed): ?self
    {
        return static::query()->where('code', self::tidy($typed))->first();
    }

    /**
     * Codes are kept in capitals, without spaces around them.
     */
    public static function tidy(string $typed): string
    {
        return mb_strtoupper(trim($typed));
    }

    /**
     * The gym's code that already has this text, archived ones included,
     * if any: a code on an old poster never means something else.
     */
    public static function codeTakenBy(string $typed, ?self $ignoring = null): ?self
    {
        $query = self::query()->withTrashed()->where('code', self::tidy($typed));

        if ($ignoring !== null) {
            $query->whereKeyNot($ignoring->getKey());
        }

        return $query->first();
    }

    /**
     * Why the code can't be used for the member's plan today, or null when it
     * can. A code that would make the plan free is allowed here; only the app
     * refuses it, since Chapa can't take nothing.
     */
    public function refusalToUse(Member $member, Plan $plan, CarbonImmutable $today): ?PromoCodeRefused
    {
        $refusal = $this->refusalFor($member, $today);

        if ($refusal !== null) {
            return $refusal;
        }

        if (! $this->isForPlan($plan)) {
            return PromoCodeRefused::notForThePlan($plan);
        }

        try {
            $this->priceFor($plan);
        } catch (InvalidDiscount) {
            return PromoCodeRefused::takesTooMuchOff($plan);
        }

        return null;
    }

    /**
     * Why the member can't use the code today, whatever the plan, or null
     * when they can: its dates, its total and once per member.
     */
    public function refusalFor(Member $member, CarbonImmutable $today): ?PromoCodeRefused
    {
        if ($this->starts_on !== null && $today->lessThan($this->starts_on)) {
            return PromoCodeRefused::notStarted($this->starts_on);
        }

        if ($this->ends_on !== null && $today->greaterThan($this->ends_on)) {
            return PromoCodeRefused::ended($this->ends_on);
        }

        if ($this->max_uses !== null && $this->usesCount() >= $this->max_uses) {
            return PromoCodeRefused::usedUp();
        }

        if ($this->once_per_member && $this->memberships()->whereBelongsTo($member)->exists()) {
            return PromoCodeRefused::alreadyUsedByTheMember();
        }

        return null;
    }

    /**
     * The plan's price with the code, or null when the code isn't for the
     * plan or takes more off than it costs.
     */
    public function priceIfFor(Plan $plan): ?Money
    {
        if (! $this->isForPlan($plan)) {
            return null;
        }

        try {
            return $this->priceFor($plan);
        } catch (InvalidDiscount) {
            return null;
        }
    }

    public function isForPlan(Plan $plan): bool
    {
        return $this->available_for_all_plans || $this->plans()->whereKey($plan->getKey())->exists();
    }

    /**
     * The discount, with the code as its reason.
     */
    public function discount(): Discount
    {
        return Discount::parse($this->discount, $this->discountReason());
    }

    public function discountReason(): string
    {
        return "Promo code {$this->code}";
    }

    /**
     * The plan's price with the code.
     *
     * @throws InvalidDiscount when the code takes more off than the plan costs
     */
    public function priceFor(Plan $plan): Money
    {
        return $this->discount()->appliedTo($plan->price);
    }

    /**
     * How many memberships were sold with the code, cancelled ones included.
     */
    public function usesCount(): int
    {
        return $this->memberships()->count();
    }

    /**
     * The ids of the plans the code is limited to, or null when it is for
     * every plan.
     *
     * @return list<int>|null
     */
    public function limitedToPlanIds(): ?array
    {
        return $this->available_for_all_plans ? null : array_map('intval', $this->plans()->pluck('plans.id')->all());
    }

    public function makeAvailableForAllPlans(): void
    {
        DB::transaction(function (): void {
            $this->plans()->detach();
            $this->forceFill(['available_for_all_plans' => true])->save();
        });
    }

    public function makeAvailableOnlyFor(Plan ...$plans): void
    {
        DB::transaction(function () use ($plans): void {
            $this->forceFill(['available_for_all_plans' => false])->save();
            $this->plans()->syncWithPivotValues(
                array_map(fn (Plan $plan): int => $plan->getKey(), $plans),
                ['organization_id' => $this->organization_id],
            );
        });
    }

    /**
     * The plans the code is limited to. Ignored while it is for every plan.
     *
     * @return BelongsToMany<Plan, $this>
     */
    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class)->withTrashed();
    }

    /**
     * The memberships sold with the code: its uses.
     *
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /** @return BelongsTo<Staff, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'created_by_staff_id')->withTrashed();
    }

    /**
     * Codes are stored in capitals, however they were typed.
     *
     * @return Attribute<string, string>
     */
    protected function code(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => self::tidy($value));
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
            'max_uses' => 'integer',
            'once_per_member' => 'boolean',
            'available_for_all_plans' => 'boolean',
        ];
    }
}
