<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Billing\Enums\DefaultExpenseCategory;
use App\Domains\Billing\Policies\ExpenseCategoryPolicy;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Tenancy\BelongsToOrganization;
use Database\Factories\ExpenseCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What a gym spends money on, such as rent or salaries (ADR 0047). Every gym
 * starts with the default list, shown in each reader's language until it is
 * renamed, and may add its own. Archiving (a soft delete) stops it being
 * chosen; expenses already recorded keep it.
 *
 * @property DefaultExpenseCategory|null $default_key
 * @property string|null $name
 */
#[UseFactory(ExpenseCategoryFactory::class)]
#[UsePolicy(ExpenseCategoryPolicy::class)]
class ExpenseCategory extends Model
{
    /** @use HasFactory<ExpenseCategoryFactory> */
    use BelongsToOrganization, HasFactory, SoftDeletes;

    /**
     * Give a newly opened gym the default categories, once.
     */
    public static function startListFor(Organization $gym): void
    {
        $has = self::query()->acrossOrganizations()->withTrashed()
            ->where('organization_id', $gym->getKey())
            ->whereNotNull('default_key')
            ->pluck('default_key')
            ->map(fn (mixed $key): string => $key instanceof DefaultExpenseCategory ? $key->value : (string) $key)
            ->all();

        foreach (DefaultExpenseCategory::cases() as $default) {
            if (! in_array($default->value, $has, true)) {
                $category = new self;
                $category->forceFill(['organization_id' => $gym->getKey(), 'default_key' => $default])->save();
            }
        }
    }

    /**
     * The gym's category that already has the name, ignoring capitals, if
     * any: a default one not renamed goes by its English label.
     */
    public static function nameTakenBy(string $name, ?self $ignoring = null): ?self
    {
        $wanted = mb_strtolower(trim($name));

        return self::query()->get()->first(fn (self $category): bool => ! $category->is($ignoring) && (
            mb_strtolower((string) $category->name) === $wanted
            || ($category->name === null && $category->default_key !== null && mb_strtolower($category->default_key->label()) === $wanted)
        ));
    }

    /**
     * Its name as the reader sees it: the gym's own, or the default's label
     * in their language.
     */
    public function displayName(): string
    {
        return $this->name ?? ($this->default_key === null ? '' : __($this->default_key->label()));
    }

    public function rename(string $name): void
    {
        $this->forceFill(['name' => trim($name)])->save();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_key' => DefaultExpenseCategory::class,
        ];
    }
}
