<?php

declare(strict_types=1);

namespace App\Domains\Memberships\Models;

use App\Domains\Activity\Enums\ActivityAction;
use App\Domains\Activity\LogsMoneyChanges;
use App\Domains\Memberships\Discount\Discount;
use App\Domains\Memberships\Policies\DiscountGroupPolicy;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\BelongsToOrganization;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Database\Factories\DiscountGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A group of members the gym prices lower, such as "Student, 10%" or "Staff
 * family, 50%", with the discount as staff type it. Members in the group pay
 * the lower price in the app and at the desk, or a promo code's when it is
 * lower still. Changing the discount changes future purchases only.
 * Archiving (a soft delete) stops it applying. See ADR 0032.
 */
#[Fillable(['name', 'discount'])]
#[UseFactory(DiscountGroupFactory::class)]
#[UsePolicy(DiscountGroupPolicy::class)]
class DiscountGroup extends Model
{
    /** @use HasFactory<DiscountGroupFactory> */
    use BelongsToOrganization, HasFactory, LogsMoneyChanges, SoftDeletes;

    /**
     * The gym's group that already has the name, ignoring capitals, if any.
     * Archived groups free theirs.
     */
    public static function nameTakenBy(string $name, ?self $ignoring = null): ?self
    {
        $query = self::query()->whereRaw('lower(name) = lower(?)', [trim($name)]);

        if ($ignoring !== null) {
            $query->whereKeyNot($ignoring->getKey());
        }

        return $query->first();
    }

    protected function moneyChangeAction(): ActivityAction
    {
        return ActivityAction::DiscountGroupSaved;
    }

    /**
     * A new group, or a change to what it takes off (ADR 0046).
     *
     * @return array<string, scalar|Money|CarbonImmutable|null>|null
     */
    protected function moneyChangeDetails(bool $created): ?array
    {
        if (! $created && ! $this->wasChanged('discount')) {
            return null;
        }

        return [
            'group' => $this->name,
            'discount' => $this->discount,
            'was_discount' => $created ? null : (string) $this->getRawOriginal('discount'),
        ];
    }

    /**
     * The discount, with the group as its reason.
     */
    public function discount(): Discount
    {
        return Discount::parse($this->discount, $this->discountReason());
    }

    public function discountReason(): string
    {
        return "Discount group {$this->name}";
    }

    /**
     * The members in the group.
     *
     * @return HasMany<MemberDiscount, $this>
     */
    public function memberDiscounts(): HasMany
    {
        return $this->hasMany(MemberDiscount::class);
    }

    /** @return BelongsTo<Staff, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'created_by_staff_id')->withTrashed();
    }
}
