<?php

declare(strict_types=1);

namespace App\Domains\PlatformBilling\Models;

use App\Casts\AsMoney;
use App\Domains\PlatformBilling\GymCharge;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Database\Factories\PlatformPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * What the platform charges a gym each month, such as "Standard: 2,000 ETB,
 * and 500 ETB for each extra branch". Prices include VAT. Paying 3, 6 or 12
 * months at once may earn a discount. Plans belong to the platform, not to a
 * gym. Retiring a plan keeps the gyms already on it, but it isn't offered for
 * new ones. See ADR 0045.
 *
 * @property string $name
 * @property Money $monthly_price Stored in monthly_price_minor and currency.
 * @property Money $extra_branch_price Stored in extra_branch_price_minor and currency.
 * @property int $discount_3_months
 * @property int $discount_6_months
 * @property int $discount_12_months
 * @property CarbonImmutable|null $retired_at
 */
#[Fillable(['name', 'monthly_price', 'extra_branch_price', 'discount_3_months', 'discount_6_months', 'discount_12_months'])]
#[UseFactory(PlatformPlanFactory::class)]
class PlatformPlan extends Model
{
    /** @use HasFactory<PlatformPlanFactory> */
    use HasFactory;

    /**
     * The same defaults as the database columns.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'discount_3_months' => 0,
        'discount_6_months' => 0,
        'discount_12_months' => 0,
    ];

    /**
     * Plans offered for new gyms: those not retired.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function offered(Builder $query): void
    {
        $query->whereNull('retired_at');
    }

    /**
     * The discount, in percent, for an invoice covering this many months.
     */
    public function discountFor(int $months): int
    {
        return match ($months) {
            3 => $this->discount_3_months,
            6 => $this->discount_6_months,
            12 => $this->discount_12_months,
            default => 0,
        };
    }

    /**
     * What a gym with this many branches pays for this many months.
     */
    public function charges(int $branches, int $months): GymCharge
    {
        return new GymCharge($this->monthly_price, $this->extra_branch_price, $branches, $months, $this->discountFor($months));
    }

    public function retire(): void
    {
        $this->forceFill(['retired_at' => CarbonImmutable::now()])->save();
    }

    public function offerAgain(): void
    {
        $this->forceFill(['retired_at' => null])->save();
    }

    public function isRetired(): bool
    {
        return $this->retired_at !== null;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monthly_price' => AsMoney::class.':monthly_price_minor,currency',
            'extra_branch_price' => AsMoney::class.':extra_branch_price_minor,currency',
            'discount_3_months' => 'integer',
            'discount_6_months' => 'integer',
            'discount_12_months' => 'integer',
            'retired_at' => 'immutable_datetime',
        ];
    }
}
