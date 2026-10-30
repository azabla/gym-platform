<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Domains\Shared\PhoneNumber\PhoneNumber;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Someone who can sign in: staff with their phone (or, for older accounts,
 * email) and a password (ADR 0019), members of a gym with a code sent to
 * their phone (ADR 0010). Every user has an email or a phone number.
 */
#[Fillable(['name', 'email', 'phone', 'password'])]
#[Hidden(['password', 'remember_token'])]
#[UseFactory(UserFactory::class)]
class User extends Authenticatable
{
    /**
     * @use HasApiTokens<PersonalAccessToken>
     * @use HasFactory<UserFactory>
     */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The user who signs in with the phone number, created the first time,
     * named after the person. A phone number reaches a user only through a
     * sign-in code sent to it, so it is always the user's own.
     */
    public static function forPhone(PhoneNumber $phone, string $name): self
    {
        return static::query()->firstOrCreate(['phone' => $phone->e164], ['name' => $name]);
    }

    /**
     * Where SMS messages to the user go: their phone, if they have one.
     */
    public function routeNotificationForSms(): ?string
    {
        return $this->phone;
    }

    /**
     * Stored in E.164 form (+251911234567), however it was typed, and read as
     * stored.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function phone(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?string => $value,
            set: fn (?string $value): ?string => $value === null ? null : PhoneNumber::of($value)->e164,
        );
    }

    /**
     * Emails are stored in lowercase, so signing in works however they are typed.
     *
     * @return Attribute<never, string|null>
     */
    protected function email(): Attribute
    {
        return Attribute::set(fn (?string $value): ?string => $value === null ? null : Str::lower($value));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
