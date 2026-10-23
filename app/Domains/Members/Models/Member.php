<?php

declare(strict_types=1);

namespace App\Domains\Members\Models;

use App\Domains\Members\Enums\Gender;
use App\Domains\Members\Policies\MemberPolicy;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Tenancy\BelongsToOrganization;
use App\Domains\Shared\PhoneNumber\PhoneNumber;
use Database\Factories\MemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A gym customer. Registered at a home branch, but welcome at every branch of
 * their gym. Archiving a member soft-deletes them. See ADR 0005.
 */
#[Fillable(['full_name', 'phone', 'email', 'gender', 'date_of_birth', 'emergency_contact_name', 'emergency_contact_phone'])]
#[UseFactory(MemberFactory::class)]
#[UsePolicy(MemberPolicy::class)]
class Member extends Model
{
    /** @use HasFactory<MemberFactory> */
    use BelongsToOrganization, HasFactory, SoftDeletes;

    /** @return BelongsTo<Branch, $this> */
    public function homeBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'home_branch_id');
    }

    /**
     * Stored in E.164 form (+251911234567), however it was typed.
     *
     * @return Attribute<never, string>
     */
    protected function phone(): Attribute
    {
        return Attribute::set(fn (string $value): string => PhoneNumber::of($value)->e164);
    }

    /**
     * Stored in E.164 form (+251911234567), however it was typed.
     *
     * @return Attribute<never, string|null>
     */
    protected function emergencyContactPhone(): Attribute
    {
        return Attribute::set(fn (?string $value): ?string => $value === null ? null : PhoneNumber::of($value)->e164);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gender' => Gender::class,
            'date_of_birth' => 'date',
        ];
    }
}
