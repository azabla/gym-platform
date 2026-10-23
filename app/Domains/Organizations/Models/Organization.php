<?php

declare(strict_types=1);

namespace App\Domains\Organizations\Models;

use Carbon\CarbonImmutable;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug'])]
#[UseFactory(OrganizationFactory::class)]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    /**
     * The same default as the database column, so a new gym knows its time zone before it is saved.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'timezone' => 'Africa/Addis_Ababa',
    ];

    /** @return HasMany<Branch, $this> */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    /** @return HasMany<Staff, $this> */
    public function staff(): HasMany
    {
        return $this->hasMany(Staff::class);
    }

    /**
     * Today's date where the gym is, at midnight in the application's time zone,
     * so it compares directly with date columns. Use it instead of now() for
     * anything that depends on the day.
     */
    public function today(): CarbonImmutable
    {
        return CarbonImmutable::parse(CarbonImmutable::now($this->timezone)->toDateString());
    }
}
