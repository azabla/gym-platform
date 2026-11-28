<?php

declare(strict_types=1);

namespace App\Domains\Organizations\Models;

use App\Domains\Identity\Models\PasswordLink;
use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Enums\SuspensionCause;
use App\Domains\Organizations\Exceptions\GymSuspensionRefused;
use App\Domains\Organizations\Onboarding\NewGym;
use App\Domains\Organizations\Policies\OrganizationPolicy;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Shared\Language\Language;
use Carbon\CarbonImmutable;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

#[Fillable(['name', 'slug', 'message_language'])]
#[UseFactory(OrganizationFactory::class)]
#[UsePolicy(OrganizationPolicy::class)]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    /**
     * Slugs the panels use for their own pages, so no gym can take them.
     */
    public const RESERVED_SLUGS = ['login', 'logout', 'register', 'password-reset', 'email-verification', 'profile', 'tenant', 'new', 'admin'];

    /**
     * Open a gym: the gym, its first branch and its owner, in one go. The
     * owner then gets an SMS to set their password, or to sign in with the
     * one they have, and adds the rest of the staff themselves. See
     * ADR 0019 and ADR 0042.
     */
    public static function open(NewGym $newGym): Staff
    {
        $gym = new self(['name' => $newGym->name, 'slug' => $newGym->slug, 'message_language' => $newGym->messageLanguage]);
        $gym->forceFill(['timezone' => $newGym->timezone]);

        $owner = DB::transaction(function () use ($gym, $newGym): Staff {
            $gym->save();

            return app(CurrentOrganization::class)->run($gym, function () use ($gym, $newGym): Staff {
                $gym->branches()->create(['name' => $newGym->branchName, 'code' => 'MAIN']);

                return Staff::add(User::forPhone($newGym->ownerPhone, $newGym->ownerName), StaffRole::Owner);
            });
        });

        PasswordLink::invite($owner->user, $gym->name);

        return $owner;
    }

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
     * Change what the platform set when it opened the gym. The slug stays:
     * it is the gym's address in links, the apps and printed QR codes.
     * See ADR 0042.
     */
    public function changeDetails(string $name, string $timezone, Language $messageLanguage): void
    {
        $this->forceFill(['name' => $name, 'timezone' => $timezone, 'message_language' => $messageLanguage])->save();
    }

    /**
     * Pause the gym: its staff, its members' app and its door stop working
     * and it sends nothing, but everything it has stays (ADR 0043).
     *
     * @throws GymSuspensionRefused when it is already paused or no reason is given
     */
    public function suspend(User $by, string $reason): void
    {
        if (trim($reason) === '') {
            throw GymSuspensionRefused::noReason();
        }

        DB::transaction(function () use ($by, $reason): void {
            $gym = self::query()->lockForUpdate()->findOrFail($this->getKey());

            if ($gym->suspended_at !== null) {
                throw GymSuspensionRefused::alreadyPaused();
            }

            $now = CarbonImmutable::now();
            $suspension = new GymSuspension;
            $suspension->forceFill([
                'organization_id' => $this->getKey(),
                'suspended_at' => $now,
                'suspended_by_user_id' => $by->getKey(),
                'reason' => trim($reason),
            ])->save();

            $this->forceFill(['suspended_at' => $now])->save();
        });
    }

    /**
     * Resume a paused gym: everything works again as it was.
     *
     * @throws GymSuspensionRefused when it isn't paused
     */
    public function resume(User $by, ?string $note = null): void
    {
        DB::transaction(function () use ($by, $note): void {
            $gym = self::query()->lockForUpdate()->findOrFail($this->getKey());

            if ($gym->suspended_at === null) {
                throw GymSuspensionRefused::notPaused();
            }

            GymSuspension::query()->acrossOrganizations()
                ->where('organization_id', $this->getKey())
                ->whereNull('resumed_at')
                ->update(['resumed_at' => CarbonImmutable::now(), 'resumed_by_user_id' => $by->getKey(), 'resume_note' => blank($note) ? null : trim((string) $note)]);

            $this->forceFill(['suspended_at' => null])->save();
        });
    }

    /**
     * Pause the gym for an unpaid invoice, as the platform itself rather than
     * an admin (ADR 0045). A gym already paused stays as it is.
     *
     * @return bool whether it was paused now
     */
    public function suspendForUnpaidBill(string $reason): bool
    {
        return DB::transaction(function () use ($reason): bool {
            $gym = self::query()->lockForUpdate()->findOrFail($this->getKey());

            if ($gym->suspended_at !== null) {
                return false;
            }

            $now = CarbonImmutable::now();
            $suspension = new GymSuspension;
            $suspension->forceFill([
                'organization_id' => $this->getKey(),
                'suspended_at' => $now,
                'cause' => SuspensionCause::UnpaidBill,
                'reason' => $reason,
            ])->save();

            $this->forceFill(['suspended_at' => $now])->save();

            return true;
        });
    }

    /**
     * Resume the gym when its unpaid bill is settled (paid, or given more
     * time), but only a pause for an unpaid bill: a pause by an admin stays
     * until an admin resumes it.
     *
     * @return bool whether it was resumed now
     */
    public function resumeFromUnpaidBill(string $note, ?User $by = null): bool
    {
        return DB::transaction(function () use ($note, $by): bool {
            self::query()->lockForUpdate()->findOrFail($this->getKey());

            $resumed = GymSuspension::query()->acrossOrganizations()
                ->where('organization_id', $this->getKey())
                ->whereNull('resumed_at')
                ->where('cause', SuspensionCause::UnpaidBill)
                ->update(['resumed_at' => CarbonImmutable::now(), 'resumed_by_user_id' => $by?->getKey(), 'resume_note' => $note]);

            if ($resumed === 0) {
                return false;
            }

            $this->forceFill(['suspended_at' => null])->save();

            return true;
        });
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /**
     * Whether the gym is paused, by the platform, for an unpaid bill.
     */
    public function isSuspendedForUnpaidBill(): bool
    {
        return $this->isSuspended() && GymSuspension::query()->acrossOrganizations()
            ->where('organization_id', $this->getKey())
            ->whereNull('resumed_at')
            ->where('cause', SuspensionCause::UnpaidBill)
            ->exists();
    }

    /**
     * Every time the gym was paused, newest first.
     *
     * @return HasMany<GymSuspension, $this>
     */
    public function suspensions(): HasMany
    {
        return $this->hasMany(GymSuspension::class)->acrossOrganizations()->latest('suspended_at');
    }

    /**
     * Gyms that aren't paused: the ones scheduled work and messages are for.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull('suspended_at');
    }

    /**
     * The owner the platform added when it opened the gym: the first owner,
     * if one is still there.
     */
    public function firstOwner(): ?Staff
    {
        return Staff::query()->acrossOrganizations()
            ->where('organization_id', $this->getKey())
            ->where('role', StaffRole::Owner)
            ->orderBy('id')
            ->first();
    }

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
            'suspended_at' => 'immutable_datetime',
        ];
    }
}
