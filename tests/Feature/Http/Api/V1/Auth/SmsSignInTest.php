<?php

declare(strict_types=1);

use App\Domains\Identity\Models\SignInCode;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Notifications\SignInCodeNotification;
use App\Domains\Members\Models\Member;
use App\Domains\Notifications\Sms\SmsChannel;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-23 09:00:00', 'Africa/Addis_Ababa'));

    Member::factory()->create(['full_name' => 'Tigist Alemu', 'phone' => '0922 34 56 78']);
});

/**
 * Ask for a sign-in code for the phone number.
 */
function askForSmsCode(string $phone): TestResponse
{
    return test()->postJson(route('api.v1.auth.sms-codes.store'), ['phone' => $phone]);
}

/**
 * Sign in with the phone number and a code, from Tigist's phone.
 */
function signInWithSmsCode(string $phone, ?string $code): TestResponse
{
    return test()->postJson(route('api.v1.auth.sms-tokens.store'), [
        'phone' => $phone,
        'code' => $code,
        'device_name' => 'Tigist\'s phone',
    ]);
}

/**
 * The code in the last sign-in SMS sent to the number (in E.164 form), if any.
 */
function lastSmsCodeSentTo(string $e164): ?string
{
    return Notification::sent(
        new AnonymousNotifiable,
        SignInCodeNotification::class,
        fn (SignInCodeNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['sms']->e164 === $e164,
    )->last()?->code;
}

/**
 * A six-digit code that isn't the one given.
 */
function anotherSmsCodeThan(?string $code): string
{
    return $code === '000000' ? '111111' : '000000';
}

it('sends a sign-in code by SMS to a number registered at a gym', function () {
    askForSmsCode('0922 34 56 78')
        ->assertAccepted()
        ->assertJsonPath('message', 'If this number is registered at a gym, a code is on its way by SMS.');

    $code = lastSmsCodeSentTo('+251922345678');
    $appName = config('app.name');

    expect($code)->toMatch('/^\d{6}$/')
        ->and(SignInCode::query()->sole()->code_hash)->not->toBe($code)->toHaveLength(64);

    Notification::assertSentOnDemand(
        SignInCodeNotification::class,
        fn (SignInCodeNotification $notification, array $channels): bool => $channels === [SmsChannel::class]
            && $notification->toSms(new AnonymousNotifiable) === "Your {$appName} sign-in code is {$code}. It expires in 10 minutes. Don't share it with anyone.",
    );
});

it('gives every number the same answer, but sends no code to a number not registered at any gym', function () {
    Member::factory()->create(['phone' => '0944 55 66 77'])->delete();

    askForSmsCode('0911 00 00 00')
        ->assertAccepted()
        ->assertJsonPath('message', 'If this number is registered at a gym, a code is on its way by SMS.');
    askForSmsCode('0944 55 66 77')->assertAccepted();

    Notification::assertNothingSent();

    expect(SignInCode::query()->exists())->toBeFalse();
});

it('signs a member in with the code, creating their user the first time only', function () {
    askForSmsCode('0922 34 56 78');

    $token = signInWithSmsCode('0922 34 56 78', lastSmsCodeSentTo('+251922345678'))
        ->assertCreated()
        ->assertJsonPath('data.user.name', 'Tigist Alemu')
        ->assertJsonPath('data.user.phone', '+251922345678')
        ->assertJsonPath('data.user.email', null)
        ->json('data.token');

    $this->withToken($token)
        ->getJson(route('api.v1.me'))
        ->assertOk()
        ->assertJsonPath('data.user.phone', '+251922345678')
        ->assertJsonPath('data.staff', []);

    $this->travel(2)->minutes();
    askForSmsCode('0922 34 56 78');

    signInWithSmsCode('09 22 34 56 78', lastSmsCodeSentTo('+251922345678'))->assertCreated();

    expect(User::query()->where('phone', '+251922345678')->count())->toBe(1);
});

it('refuses a wrong code, and the right code once its 10 minutes are over', function () {
    askForSmsCode('0922 34 56 78');
    $code = lastSmsCodeSentTo('+251922345678');

    signInWithSmsCode('0922 34 56 78', anotherSmsCodeThan($code))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code' => 'This code is wrong or has expired. Ask for a new one.']);

    $this->travel(11)->minutes();

    signInWithSmsCode('0922 34 56 78', $code)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code' => 'This code is wrong or has expired. Ask for a new one.']);
});

it('accepts only the newest code, and only once', function () {
    askForSmsCode('0922 34 56 78');
    $olderCode = lastSmsCodeSentTo('+251922345678');
    $this->travel(2)->minutes();
    askForSmsCode('0922 34 56 78');
    $newestCode = lastSmsCodeSentTo('+251922345678');

    signInWithSmsCode('0922 34 56 78', $olderCode)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code' => 'This code is wrong or has expired. Ask for a new one.']);

    signInWithSmsCode('0922 34 56 78', $newestCode)->assertCreated();

    signInWithSmsCode('0922 34 56 78', $newestCode)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code' => 'This code is wrong or has expired. Ask for a new one.']);
});

it('stops accepting a code after five wrong tries', function () {
    askForSmsCode('0922 34 56 78');
    $code = lastSmsCodeSentTo('+251922345678');

    foreach (range(1, 5) as $try) {
        signInWithSmsCode('0922 34 56 78', anotherSmsCodeThan($code))->assertUnprocessable();
    }

    // Signing in allows five tries a minute.
    $this->travel(61)->seconds();

    signInWithSmsCode('0922 34 56 78', $code)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code' => 'This code is wrong or has expired. Ask for a new one.']);
});

it('sends one code a minute to each number, however it is typed', function () {
    askForSmsCode('0922 34 56 78')->assertAccepted();
    askForSmsCode('+251 922 345 678')->assertTooManyRequests();

    $this->travel(61)->seconds();

    askForSmsCode('0922 34 56 78')->assertAccepted();
});
