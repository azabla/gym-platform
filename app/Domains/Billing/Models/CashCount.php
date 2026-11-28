<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Casts\AsMoney;
use App\Domains\Activity\Activity;
use App\Domains\Activity\Enums\ActivityAction;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Enums\PaymentType;
use App\Domains\Billing\Exceptions\CashCountRefused;
use App\Domains\Billing\Policies\CashCountPolicy;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\BelongsToOrganization;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A staff member's cash count at a branch (ADR 0046): the cash they took
 * there since their last count, what they counted, and a note when the two
 * differ. An owner or manager marks it checked. A cash payment counted once
 * stays with its count; voiding it later leaves the count as it was, and the
 * log shows the void.
 *
 * @property int $branch_id
 * @property int $staff_id
 * @property Money $expected
 * @property Money $counted
 * @property string|null $note
 * @property CarbonImmutable $closed_at
 * @property int|null $checked_by_staff_id
 * @property CarbonImmutable|null $checked_at
 * @property string|null $check_note
 * @property-read Staff $staff
 * @property-read Branch $branch
 * @property-read Staff|null $checkedBy
 */
#[UsePolicy(CashCountPolicy::class)]
class CashCount extends Model
{
    use BelongsToOrganization;

    /**
     * The cash the staff member took at the branch and hasn't counted yet:
     * cash payments less cash refunds, not voided.
     */
    public static function expectedFor(Staff $staff, Branch $branch): Money
    {
        return self::sumOf(self::uncounted($staff, $branch));
    }

    /**
     * Close the staff member's count at the branch: what they counted, with
     * a note when it differs from what they should have. Their uncounted cash
     * payments are locked first, so two counts at once can't take the same
     * ones; cash taken after this goes to the next count.
     *
     * @throws CashCountRefused when there is nothing to count or a difference has no note
     */
    public static function close(Staff $staff, Branch $branch, Money $counted, ?string $note): self
    {
        $note = blank($note) ? null : trim((string) $note);

        return DB::transaction(function () use ($staff, $branch, $counted, $note): self {
            $payments = self::uncounted($staff, $branch)->lockForUpdate()->get();

            if ($payments->isEmpty()) {
                throw CashCountRefused::nothingToCount();
            }

            $expected = self::sumOf(Payment::query()->whereKey($payments->modelKeys()));
            $difference = $counted->subtract($expected);

            if (! $difference->isZero() && $note === null) {
                throw CashCountRefused::differenceNeedsANote($difference);
            }

            $count = new self;
            $count->forceFill([
                'organization_id' => $staff->organization_id,
                'branch_id' => $branch->getKey(),
                'staff_id' => $staff->getKey(),
                'expected' => $expected,
                'counted' => $counted,
                'note' => $note,
                'closed_at' => CarbonImmutable::now(),
            ])->save();

            Payment::query()->whereKey($payments->modelKeys())->update(['cash_count_id' => $count->getKey()]);

            Activity::record(ActivityAction::CashCounted, $count, $staff, $branch->getKey(), [
                'expected' => $expected,
                'counted' => $counted,
                'difference' => $difference,
                'payments' => $payments->count(),
                'note' => $note,
            ], flagged: ! $difference->isZero());

            return $count;
        });
    }

    /**
     * An owner or manager has looked at the count, with a note if they like.
     * Nobody checks their own count but an owner.
     *
     * @throws CashCountRefused when it is already checked, or it is the checker's own
     */
    public function check(Staff $by, ?string $note): void
    {
        DB::transaction(function () use ($by, $note): void {
            $count = self::query()->lockForUpdate()->findOrFail($this->getKey());

            if ($count->checked_at !== null) {
                throw CashCountRefused::alreadyChecked();
            }

            if ($count->staff_id === $by->getKey() && $by->role !== StaffRole::Owner) {
                throw CashCountRefused::ownCount();
            }

            $this->forceFill([
                'checked_by_staff_id' => $by->getKey(),
                'checked_at' => CarbonImmutable::now(),
                'check_note' => blank($note) ? null : trim((string) $note),
            ])->save();

            Activity::record(ActivityAction::CashCountChecked, $this, $by, $this->branch_id, [
                'counted' => $this->counted,
                'difference' => $this->difference(),
                'note' => $this->check_note,
            ]);
        });
    }

    /**
     * Counted less expected: positive for cash over, negative for cash short.
     */
    public function difference(): Money
    {
        return $this->counted->subtract($this->expected);
    }

    /**
     * Who has cash taken and not yet counted, at which branch, and how much:
     * for the reminder on the dashboard.
     *
     * @param  list<int>|null  $branchIds  null for every branch
     * @return list<array{staff_id: int, branch_id: int, amount: Money}>
     */
    public static function waitingToBeCounted(?array $branchIds): array
    {
        $rows = Payment::query()
            ->where('method', PaymentMethod::Cash)
            ->whereNull('voided_at')
            ->whereNull('cash_count_id')
            ->whereNotNull('received_by_staff_id')
            ->when($branchIds !== null, fn (Builder $query) => $query->whereIn('branch_id', $branchIds))
            ->groupBy('received_by_staff_id', 'branch_id')
            ->selectRaw('received_by_staff_id, branch_id, SUM(CASE WHEN type = ? THEN -amount_minor ELSE amount_minor END) AS amount', [PaymentType::Refund->value])
            ->toBase()
            ->get();
        $waiting = [];

        foreach ($rows as $row) {
            $waiting[] = ['staff_id' => (int) $row->received_by_staff_id, 'branch_id' => (int) $row->branch_id, 'amount' => Money::ofMinor((int) $row->amount)];
        }

        return $waiting;
    }

    /**
     * Counts not checked yet.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function unchecked(Builder $query): void
    {
        $query->whereNull('checked_at');
    }

    /** @return BelongsTo<Staff, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class)->withTrashed();
    }

    /** @return BelongsTo<Staff, $this> */
    public function checkedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'checked_by_staff_id')->withTrashed();
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * The staff member's cash payments and refunds at the branch, not voided
     * and not yet counted.
     *
     * @return Builder<Payment>
     */
    private static function uncounted(Staff $staff, Branch $branch): Builder
    {
        return Payment::query()
            ->where('method', PaymentMethod::Cash)
            ->where('received_by_staff_id', $staff->getKey())
            ->where('branch_id', $branch->getKey())
            ->whereNull('voided_at')
            ->whereNull('cash_count_id');
    }

    /**
     * Payments less refunds.
     *
     * @param  Builder<Payment>  $payments
     */
    private static function sumOf(Builder $payments): Money
    {
        $minor = (int) $payments->toBase()->selectRaw('COALESCE(SUM(CASE WHEN type = ? THEN -amount_minor ELSE amount_minor END), 0) AS total', [PaymentType::Refund->value])->value('total');

        return Money::ofMinor($minor);
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
            'expected' => AsMoney::class.':expected_minor,currency',
            'counted' => AsMoney::class.':counted_minor,currency',
            'closed_at' => 'immutable_datetime',
            'checked_by_staff_id' => 'integer',
            'checked_at' => 'immutable_datetime',
        ];
    }
}
