<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\PasswordLinkPurpose;
use App\Domains\Identity\Models\PasswordLink;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Notifications\PasswordLinkNotification;
use App\Domains\Identity\Notifications\StaffAddedNotification;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00:00', 'Africa/Addis_Ababa'));

    $this->dawit = User::factory()->withPhone('0911 22 33 44')->create(['name' => 'Dawit Bekele']);
    Staff::factory()->for($this->dawit)->for(Organization::factory()->create(['name' => 'Demo Gym']))->create();
});

/**
 * The token in the last password link sent to the user, if any.
 */
function lastPasswordLinkTokenFor(User $user): ?string
{
    $token = null;

    Notification::assertSentTo($user, PasswordLinkNotification::class, function (PasswordLinkNotification $notification) use (&$token): bool {
        $token = $notification->token;

        return true;
    });

    return $token;
}

it('invites staff without a password by SMS, with a link that names the gym', function () {
    PasswordLink::invite($this->dawit, 'Demo Gym');

    Notification::assertSentTo($this->dawit, PasswordLinkNotification::class, function (PasswordLinkNotification $notification): bool {
        $sms = $notification->toSms($this->dawit);

        return $notification->purpose === PasswordLinkPurpose::Invite
            && str_contains($sms, 'Demo Gym added you as staff')
            && str_contains($sms, route('staff.password.edit', ['token' => $notification->token]));
    });
});

it('only tells staff who already have a password that they were added', function () {
    $this->dawit->forceFill(['password' => 'their-own-password'])->save();

    PasswordLink::invite($this->dawit, 'Demo Gym');

    Notification::assertNotSentTo($this->dawit, PasswordLinkNotification::class);
    Notification::assertSentTo($this->dawit, StaffAddedNotification::class);
});

it('sets the password from the link, which then works no more', function () {
    PasswordLink::invite($this->dawit, 'Demo Gym');
    $token = lastPasswordLinkTokenFor($this->dawit);

    $this->get(route('staff.password.edit', ['token' => $token]))
        ->assertOk()
        ->assertSee('Hello Dawit Bekele');

    $this->post(route('staff.password.update', ['token' => $token]), ['password' => 'a-new-password', 'password_confirmation' => 'a-new-password'])
        ->assertRedirect(route('staff.password.done'));

    expect(Hash::check('a-new-password', (string) $this->dawit->fresh()->password))->toBeTrue();

    $this->get(route('staff.password.edit', ['token' => $token]))->assertGone();
    $this->post(route('staff.password.update', ['token' => $token]), ['password' => 'another-password', 'password_confirmation' => 'another-password'])
        ->assertGone();
    expect(Hash::check('a-new-password', (string) $this->dawit->fresh()->password))->toBeTrue();
});

it('checks the password chosen', function () {
    $token = PasswordLink::issue($this->dawit, PasswordLinkPurpose::Invite);

    $this->from(route('staff.password.edit', ['token' => $token]))
        ->post(route('staff.password.update', ['token' => $token]), ['password' => 'short', 'password_confirmation' => 'short'])
        ->assertSessionHasErrors('password');
    $this->post(route('staff.password.update', ['token' => $token]), ['password' => 'a-new-password', 'password_confirmation' => 'different-one'])
        ->assertSessionHasErrors('password');

    expect($this->dawit->fresh()->password)->toBeNull();
});

it('refuses an expired link, an unknown one, and one replaced by a newer link', function () {
    $invite = PasswordLink::issue($this->dawit, PasswordLinkPurpose::Invite);

    $this->travel(7 * 24 * 60 + 1)->minutes();
    $this->get(route('staff.password.edit', ['token' => $invite]))->assertGone();
    $this->get(route('staff.password.edit', ['token' => 'not-a-real-token']))->assertGone();

    $older = PasswordLink::issue($this->dawit, PasswordLinkPurpose::Reset);
    $newer = PasswordLink::issue($this->dawit, PasswordLinkPurpose::Reset);

    $this->get(route('staff.password.edit', ['token' => $older]))->assertGone();
    $this->get(route('staff.password.edit', ['token' => $newer]))->assertOk();

    $this->travel(61)->minutes();
    $this->get(route('staff.password.edit', ['token' => $newer]))->assertGone();
});

it('sends a reset link to staff who forgot their password, and the same answer to anyone else', function () {
    $member = User::factory()->withPhone('0922 34 56 78')->create();

    $this->post(route('staff.password.send'), ['phone' => '0911 22 33 44'])
        ->assertRedirect(route('staff.password.forgot'))
        ->assertSessionHas('status', 'If this number belongs to a staff member, a link to reset the password is on its way by SMS.');
    $this->post(route('staff.password.send'), ['phone' => '0922 34 56 78'])
        ->assertSessionHas('status', 'If this number belongs to a staff member, a link to reset the password is on its way by SMS.');
    $this->post(route('staff.password.send'), ['phone' => '0933 00 00 00'])
        ->assertSessionHas('status', 'If this number belongs to a staff member, a link to reset the password is on its way by SMS.');

    Notification::assertSentTo($this->dawit, PasswordLinkNotification::class, fn (PasswordLinkNotification $notification): bool => $notification->purpose === PasswordLinkPurpose::Reset);
    Notification::assertNotSentTo($member, PasswordLinkNotification::class);
    Notification::assertSentTimes(PasswordLinkNotification::class, 1);
});

it('limits how often a link can be asked for', function () {
    $this->post(route('staff.password.send'), ['phone' => '0911 22 33 44'])->assertRedirect();
    $this->post(route('staff.password.send'), ['phone' => '0911 22 33 44'])->assertTooManyRequests();
});

it('deletes links a day after they expire', function () {
    PasswordLink::issue($this->dawit, PasswordLinkPurpose::Reset);
    $this->travel(2)->days();

    $this->artisan('model:prune', ['--model' => [PasswordLink::class]])->assertSuccessful();

    expect(PasswordLink::query()->count())->toBe(0);
});

it('shows the forgot-password page', function () {
    $this->get(route('staff.password.forgot'))
        ->assertOk()
        ->assertSee('Forgot your password?');
});
