<?php

declare(strict_types=1);

use App\Domains\Notifications\Sms\AfroMessageSmsSender;
use App\Domains\Notifications\Sms\Exceptions\SmsNotSent;
use App\Domains\Notifications\Sms\LogSmsSender;
use App\Domains\Notifications\Sms\SendableBySms;
use App\Domains\Notifications\Sms\SmsChannel;
use App\Domains\Notifications\Sms\SmsSender;
use App\Domains\Shared\PhoneNumber\PhoneNumber;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'services.afromessage.token' => 'afro-test-token',
        'services.afromessage.identifier_id' => 'e80ad9d8-adf3-463f-80f4-7c4b39f7f164',
        'services.afromessage.sender_name' => 'GymPlatform',
    ]);
});

it('sends a message through AfroMessage with the platform\'s account', function () {
    Http::fake(['api.afromessage.com/api/send' => Http::response([
        'acknowledge' => 'success',
        'response' => ['status' => 'Send in progress', 'message_id' => 'b1ce2e9c', 'message' => 'Selam', 'to' => '+251922345678'],
    ])]);

    (new AfroMessageSmsSender)->send(PhoneNumber::of('0922 34 56 78'), 'Your Gym Platform code is 123456.');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.afromessage.com/api/send'
        && $request->hasHeader('Authorization', 'Bearer afro-test-token')
        && $request['to'] === '+251922345678'
        && $request['message'] === 'Your Gym Platform code is 123456.'
        && $request['from'] === 'e80ad9d8-adf3-463f-80f4-7c4b39f7f164'
        && $request['sender'] === 'GymPlatform');
});

it('reports a message AfroMessage refused, or no answer', function (Closure $answer, string $message) {
    Http::fake(['api.afromessage.com/api/send' => $answer]);

    expect(fn () => (new AfroMessageSmsSender)->send(PhoneNumber::of('0922 34 56 78'), 'Selam'))
        ->toThrow(SmsNotSent::class, $message);
})->with([
    'refused' => [
        fn () => Http::response(['acknowledge' => 'error', 'response' => ['errors' => ['Insufficient balance.']]]),
        'The SMS provider refused the message: Insufficient balance.',
    ],
    'a server error' => [fn () => Http::response(null, 503), 'The SMS provider didn\'t answer.'],
    'no answer' => [fn () => throw new ConnectionException('Connection timed out.'), 'The SMS provider didn\'t answer.'],
]);

it('writes the message to the log instead, until an SMS provider is set up', function () {
    Log::spy();

    (new LogSmsSender)->send(PhoneNumber::of('0922 34 56 78'), 'Your Gym Platform code is 123456.');

    Log::shouldHaveReceived('info')->once()->with('SMS to +251922345678: Your Gym Platform code is 123456.');
});

it('uses the driver SMS_DRIVER names', function () {
    config(['services.sms.driver' => 'afromessage']);
    expect(app(SmsSender::class))->toBeInstanceOf(AfroMessageSmsSender::class);

    config(['services.sms.driver' => 'log']);
    expect(app(SmsSender::class))->toBeInstanceOf(LogSmsSender::class);

    config(['services.sms.driver' => 'pigeon']);
    expect(fn () => app(SmsSender::class))->toThrow(InvalidArgumentException::class, 'SMS_DRIVER must be "log" or "afromessage".');
});

it('sends a notification by SMS to the phone it is routed to', function (string|PhoneNumber $route) {
    $this->mock(SmsSender::class, function (MockInterface $mock): void {
        $mock->shouldReceive('send')
            ->once()
            ->withArgs(fn (PhoneNumber $to, string $message): bool => $to->e164 === '+251922345678' && $message === 'Selam from the gym.');
    });

    (new AnonymousNotifiable)->route('sms', $route)->notifyNow(new class extends Notification implements SendableBySms
    {
        /**
         * @return list<class-string>
         */
        public function via(object $notifiable): array
        {
            return [SmsChannel::class];
        }

        public function toSms(object $notifiable): string
        {
            return 'Selam from the gym.';
        }
    });
})->with([
    'as typed' => ['0922 34 56 78'],
    'as a phone number' => [PhoneNumber::of('0922 34 56 78')],
]);
