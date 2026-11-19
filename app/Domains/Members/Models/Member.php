<?php

declare(strict_types=1);

namespace App\Domains\Members\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Members\Enums\Gender;
use App\Domains\Members\Policies\MemberPolicy;
use App\Domains\Notifications\Enums\NotificationTopic;
use App\Domains\Notifications\Enums\PushApp;
use App\Domains\Notifications\Models\PushToken;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Tenancy\BelongsToOrganization;
use App\Domains\Shared\Language\Language;
use App\Domains\Shared\PhoneNumber\PhoneNumber;
use Database\Factories\MemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;

/**
 * A gym customer. Registered at a home branch, but welcome at every branch of
 * their gym. Archiving a member soft-deletes them. See ADR 0005.
 *
 * Messages to a member (ADR 0012) go to this record, so each gym has its own
 * inbox: by SMS to the member's phone, and by push to every phone signed in
 * with that number.
 */
#[Fillable(['full_name', 'phone', 'email', 'gender', 'date_of_birth', 'emergency_contact_name', 'emergency_contact_phone', 'message_language'])]
#[UseFactory(MemberFactory::class)]
#[UsePolicy(MemberPolicy::class)]
class Member extends Model
{
    /** @use HasFactory<MemberFactory> */
    use BelongsToOrganization, HasFactory, Notifiable, SoftDeletes;

    /** @return BelongsTo<Branch, $this> */
    public function homeBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'home_branch_id');
    }

    /**
     * Whether the member wants pushes about the topic at this gym. Every topic
     * is on until the member turns it off; the inbox gets everything anyway.
     */
    public function wantsPushesAbout(NotificationTopic $topic): bool
    {
        return ($this->notification_preferences[$topic->value] ?? true) === true;
    }

    /**
     * Turn pushes about the given topics on or off, keeping the others.
     *
     * @param  array<string, bool>  $choices  by topic, such as ["visits" => false]
     */
    public function choosePushes(array $choices): void
    {
        $this->notification_preferences = [...($this->notification_preferences ?? []), ...$choices];
        $this->save();
    }

    /**
     * Whether pushes about each topic are on, by topic.
     *
     * @return array<string, bool>
     */
    public function pushPreferences(): array
    {
        $preferences = [];

        foreach (NotificationTopic::cases() as $topic) {
            $preferences[$topic->value] = $this->wantsPushesAbout($topic);
        }

        return $preferences;
    }

    /**
     * The language the member's SMS are written in: their own choice, else
     * their gym's. See ADR 0034.
     */
    public function messageLanguage(): Language
    {
        if ($this->message_language !== null) {
            return $this->message_language;
        }

        return $this->organization->messageLanguage();
    }

    /**
     * SMS goes to the member's phone.
     */
    public function routeNotificationForSms(): PhoneNumber
    {
        return PhoneNumber::of((string) $this->getAttribute('phone'));
    }

    /**
     * Pushes go to every phone signed in to the members app as the user with
     * the member's phone number (ADR 0035).
     *
     * @return Collection<int, PushToken>
     */
    public function routeNotificationForPush(): Collection
    {
        return PushToken::query()
            ->whereIn('user_id', User::query()->where('phone', $this->getAttribute('phone'))->select('id'))
            ->where('app', PushApp::Members->value)
            ->get();
    }

    /**
     * Members whose home branch is any of the branches; every member when the
     * branches are null (every branch). See ADR 0025.
     *
     * @param  Builder<self>  $query
     * @param  list<int>|null  $branchIds
     */
    #[Scope]
    protected function homeAtAnyOf(Builder $query, ?array $branchIds): void
    {
        if ($branchIds !== null) {
            $query->whereIn('home_branch_id', $branchIds);
        }
    }

    /**
     * Members whose name contains the text, or whose phone number contains its
     * digits ("0911 23" finds +251911234567). "%" and "_" match literally.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function search(Builder $query, string $text): void
    {
        $digits = ltrim(preg_replace('/\D/', '', $text) ?? '', '0');

        $query->where(function ($query) use ($text, $digits): void {
            $query->whereLike('full_name', '%'.addcslashes($text, '\\%_').'%');

            if (strlen($digits) >= 3) {
                $query->orWhereLike('phone', '%'.$digits.'%');
            }
        });
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
            'notification_preferences' => 'array',
            'message_language' => Language::class,
        ];
    }
}
