<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Casts\AsMoney;
use App\Domains\Activity\Activity;
use App\Domains\Activity\Enums\ActivityAction;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Exceptions\ExpenseRefused;
use App\Domains\Billing\Policies\ExpensePolicy;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\BelongsToOrganization;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Money the gym spent (ADR 0047): on a category, at a branch or for the whole
 * gym, on the gym's date it was paid, how and to whom. A mistake is voided
 * with a reason and stays listed, no longer counted. Profit is money taken
 * less what was spent, each by the day it was paid.
 *
 * @property int|null $branch_id
 * @property int $expense_category_id
 * @property Money $amount
 * @property CarbonImmutable $paid_on
 * @property PaymentMethod $method
 * @property string|null $payee
 * @property string|null $reference
 * @property string|null $note
 * @property string|null $receipt_path
 * @property int|null $recorded_by_staff_id
 * @property CarbonImmutable|null $voided_at
 * @property string|null $void_reason
 * @property-read ExpenseCategory $category
 * @property-read Branch|null $branch
 * @property-read Staff|null $recordedBy
 * @property-read Staff|null $voidedBy
 */
#[UseFactory(ExpenseFactory::class)]
#[UsePolicy(ExpensePolicy::class)]
class Expense extends Model
{
    /** @use HasFactory<ExpenseFactory> */
    use BelongsToOrganization, HasFactory;

    /** The roles that record expenses for the whole gym, not one branch. */
    public const ROLES_FOR_THE_WHOLE_GYM = [StaffRole::Owner, StaffRole::Accountant];

    /**
     * Record what was spent. A branch must be one the staff member works at;
     * no branch means the whole gym, which only owners and accountants record.
     * Logged in the money log, flagged when dated before today (ADR 0046).
     *
     * @throws ExpenseRefused when the amount, day, branch, category or method isn't allowed
     */
    public static function record(
        Staff $by,
        ExpenseCategory $category,
        Money $amount,
        CarbonImmutable $paidOn,
        PaymentMethod $method,
        ?Branch $branch,
        ?string $payee = null,
        ?string $reference = null,
        ?string $note = null,
    ): self {
        $today = Organization::query()->findOrFail($by->organization_id)->today();

        match (true) {
            ! $amount->isPositive() => throw ExpenseRefused::nothingSpent(),
            $paidOn->greaterThan($today) => throw ExpenseRefused::paidInTheFuture(),
            $category->trashed() => throw ExpenseRefused::categoryArchived(),
            ! in_array($method, PaymentMethod::forTheDesk(), true) => throw ExpenseRefused::methodNotForExpenses(),
            $branch === null && ! in_array($by->role, self::ROLES_FOR_THE_WHOLE_GYM, true) => throw ExpenseRefused::wholeGymNotTheirs(),
            $branch !== null && ! $by->worksAt($branch) => throw ExpenseRefused::notTheirBranch(),
            default => null,
        };

        return DB::transaction(function () use ($by, $category, $amount, $paidOn, $method, $branch, $payee, $reference, $note, $today): self {
            $expense = new self;
            $expense->forceFill([
                'organization_id' => $by->organization_id,
                'branch_id' => $branch?->getKey(),
                'expense_category_id' => $category->getKey(),
                'amount' => $amount,
                'paid_on' => $paidOn,
                'method' => $method,
                'payee' => self::tidy($payee),
                'reference' => self::tidy($reference),
                'note' => self::tidy($note),
                'recorded_by_staff_id' => $by->getKey(),
            ])->save();

            $backdated = $paidOn->lessThan($today);
            Activity::record(ActivityAction::ExpenseRecorded, $expense, $by, $branch?->getKey(), [
                'category' => $category->displayName(),
                'amount' => $amount,
                'method' => $method->value,
                'payee' => $expense->payee,
                'paid_on' => $paidOn,
                'backdated' => $backdated,
            ], flagged: $backdated);

            return $expense;
        });
    }

    /**
     * Void the expense, because it was recorded by mistake: it stays listed,
     * with who voided it and why, but no longer counts.
     *
     * @throws ExpenseRefused when it is already voided or no reason is given
     */
    public function void(Staff $by, string $reason): void
    {
        if (trim($reason) === '') {
            throw ExpenseRefused::noReason();
        }

        DB::transaction(function () use ($by, $reason): void {
            $expense = self::query()->lockForUpdate()->findOrFail($this->getKey());

            if ($expense->voided_at !== null) {
                throw ExpenseRefused::alreadyVoided();
            }

            $this->forceFill(['voided_at' => CarbonImmutable::now(), 'voided_by_staff_id' => $by->getKey(), 'void_reason' => trim($reason)])->save();

            Activity::record(ActivityAction::ExpenseVoided, $this, $by, $this->branch_id, [
                'category' => $this->category()->withTrashed()->first()?->displayName(),
                'amount' => $this->amount,
                'reason' => trim($reason),
            ]);
        });
    }

    /**
     * What was spent, and by category, for the expenses the query finds,
     * voided ones left out.
     *
     * @param  Builder<self>  $expenses
     * @return array{spent: Money, count: int, by_category: array<int, Money>}
     */
    public static function totalsOf(Builder $expenses): array
    {
        $rows = (clone $expenses)->counted()
            ->reorder()
            ->groupBy('expense_category_id')
            ->selectRaw('expense_category_id, SUM(amount_minor) AS spent, COUNT(*) AS expenses')
            ->toBase()
            ->get();
        $byCategory = [];
        $spent = 0;
        $count = 0;

        foreach ($rows as $row) {
            $byCategory[(int) $row->expense_category_id] = Money::ofMinor((int) $row->spent);
            $spent += (int) $row->spent;
            $count += (int) $row->expenses;
        }

        return ['spent' => Money::ofMinor($spent), 'count' => $count, 'by_category' => $byCategory];
    }

    /**
     * Expenses that count: not voided.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function counted(Builder $query): void
    {
        $query->whereNull('voided_at');
    }

    /**
     * The expenses a staff member sees: their branches', and the whole gym's
     * for owners and accountants (ADR 0047).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function seenBy(Builder $query, Staff $staff): void
    {
        $branchIds = $staff->limitedToBranchIds();
        $seesTheWholeGym = in_array($staff->role, self::ROLES_FOR_THE_WHOLE_GYM, true);

        $query->where(function (Builder $query) use ($branchIds, $seesTheWholeGym): void {
            if ($branchIds === null) {
                $query->whereNotNull('branch_id');
            } else {
                $query->whereIn('branch_id', $branchIds);
            }

            if ($seesTheWholeGym) {
                $query->orWhereNull('branch_id');
            }
        });
    }

    /** @return BelongsTo<ExpenseCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id')->withTrashed();
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Staff, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'recorded_by_staff_id')->withTrashed();
    }

    /** @return BelongsTo<Staff, $this> */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'voided_by_staff_id')->withTrashed();
    }

    private static function tidy(?string $text): ?string
    {
        return $text === null || trim($text) === '' ? null : trim($text);
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
            'expense_category_id' => 'integer',
            'amount' => AsMoney::class.':amount_minor,currency',
            'paid_on' => 'immutable_date',
            'method' => PaymentMethod::class,
            'recorded_by_staff_id' => 'integer',
            'voided_at' => 'immutable_datetime',
        ];
    }
}
