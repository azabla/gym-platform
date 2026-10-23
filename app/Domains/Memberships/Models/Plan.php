<?php

declare(strict_types=1);

namespace App\Domains\Memberships\Models;

use App\Casts\AsMoney;
use App\Domains\Memberships\Enums\DurationUnit;
use App\Domains\Memberships\Policies\PlanPolicy;
use App\Domains\Organizations\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Something a gym sells: a price and a length in days or months, such as
 * "Monthly, 2,500 ETB". Archiving a plan (a soft delete) stops new sales, while
 * memberships already sold keep their own dates and price. See ADR 0006.
 */
#[Fillable(['name', 'description', 'price', 'duration_count', 'duration_unit'])]
#[UseFactory(PlanFactory::class)]
#[UsePolicy(PlanPolicy::class)]
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use BelongsToOrganization, HasFactory, SoftDeletes;

    /**
     * The last day of a membership of this plan that starts on the given day.
     * Both days are included: a 1-month plan starting 15 March runs through
     * 14 April, and a 30-day plan starting 1 March runs through 30 March. When
     * the next month is too short for the start day, the membership runs
     * through that month's last day: starting 31 January, through 28 February.
     */
    public function endsOnFor(CarbonImmutable $startsOn): CarbonImmutable
    {
        if ($this->duration_unit === DurationUnit::Day) {
            return $startsOn->addDays($this->duration_count - 1);
        }

        $sameDayLater = $startsOn->addMonthsNoOverflow($this->duration_count);

        return $sameDayLater->day === $startsOn->day ? $sameDayLater->subDay() : $sameDayLater;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => AsMoney::class.':price_minor,currency',
            'duration_count' => 'integer',
            'duration_unit' => DurationUnit::class,
        ];
    }
}
