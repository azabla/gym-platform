<?php

declare(strict_types=1);

namespace App\Domains\Organizations\Models;

use App\Domains\Organizations\Policies\OrganizationPolicy;
use App\Domains\Shared\Language\Language;
use Carbon\CarbonImmutable;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'message_language'])]
#[UseFactory(OrganizationFactory::class)]
#[UsePolicy(OrganizationPolicy::class)]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    /**
     * The same defaults as the database columns, so a new gym knows its time
     * zone and message language before it is saved.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'timezone' => 'Africa/Addis_Ababa',
        'message_language' => 'en',
    ];

    /**
     * The language the gym's members get messages in, unless a member has
     * chosen their own. See ADR 0034.
     */
    public function messageLanguage(): Language
    {
        return $this->message_language;
    }

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

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'message_language' => Language::class,
        ];
    }
}
