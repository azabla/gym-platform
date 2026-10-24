<?php

declare(strict_types=1);

use App\Domains\Notifications\Push\Exceptions\PushNotSent;
use App\Domains\Notifications\Push\FcmPushSender;
use App\Domains\Notifications\Push\PushMessage;
use App\Domains\Notifications\Push\PushResult;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();

    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $privateKey);
    $this->publicKey = openssl_pkey_get_details($key)['key'];

    $this->credentialsPath = tempnam(sys_get_temp_dir(), 'fcm');
    file_put_contents($this->credentialsPath, json_encode([
        'type' => 'service_account',
        'client_email' => 'push@volt-prod.iam.gserviceaccount.com',
        'private_key' => $privateKey,
        'token_uri' => 'https://oauth2.googleapis.com/token',
    ]));

    config([
        'services.fcm.project_id' => 'volt-prod',
        'services.fcm.credentials' => $this->credentialsPath,
    ]);

    $this->message = new PushMessage('Payment received', 'Your payment at Demo Gym was recorded.', [
        'type' => 'payment_succeeded',
        'payment_id' => '12',
    ]);
});

afterEach(function () {
    @unlink($this->credentialsPath);
});

/**
 * Fake Google answering the token request, and FCM answering a send as given.
 */
function fakeFcm(int $status = 200, array $answer = ['name' => 'projects/volt-prod/messages/0:1']): void
{
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.fresh', 'expires_in' => 3599, 'token_type' => 'Bearer']),
        'fcm.googleapis.com/*' => Http::response($answer, $status),
    ]);
}

it('signs a token request with the service account, then sends the push with string data', function () {
    fakeFcm();

    expect((new FcmPushSender)->send('phone-token-1', $this->message))->toBe(PushResult::Sent);

    $tokenRequest = Http::recorded(fn (Request $request) => $request->url() === 'https://oauth2.googleapis.com/token')->sole()[0];
    [$header, $claims, $signature] = explode('.', $tokenRequest['assertion']);
    $decode = fn (string $part) => base64_decode(strtr($part, '-_', '+/'));
    $payload = json_decode($decode($claims), true);
    expect($tokenRequest['grant_type'])->toBe('urn:ietf:params:oauth:grant-type:jwt-bearer')
        ->and(json_decode($decode($header), true))->toBe(['alg' => 'RS256', 'typ' => 'JWT'])
        ->and($payload['iss'])->toBe('push@volt-prod.iam.gserviceaccount.com')
        ->and($payload['scope'])->toBe('https://www.googleapis.com/auth/firebase.messaging')
        ->and($payload['aud'])->toBe('https://oauth2.googleapis.com/token')
        ->and(openssl_verify("{$header}.{$claims}", $decode($signature), $this->publicKey, OPENSSL_ALGO_SHA256))->toBe(1);

    $send = Http::recorded(fn (Request $request) => str_starts_with($request->url(), 'https://fcm.googleapis.com/'))->sole()[0];
    expect($send->url())->toBe('https://fcm.googleapis.com/v1/projects/volt-prod/messages:send')
        ->and($send->header('Authorization'))->toBe(['Bearer ya29.fresh'])
        ->and($send['message']['token'])->toBe('phone-token-1')
        ->and($send['message']['notification'])->toBe(['title' => 'Payment received', 'body' => 'Your payment at Demo Gym was recorded.'])
        ->and($send['message']['data'])->toBe(['type' => 'payment_succeeded', 'payment_id' => '12'])
        ->and($send['message']['android'])->toBe(['priority' => 'HIGH']);
});

it('reuses Google\'s access token for later pushes', function () {
    fakeFcm();
    $sender = new FcmPushSender;

    $sender->send('phone-token-1', $this->message);
    $sender->send('phone-token-2', $this->message);

    Http::assertSentCount(3);
});

it('reports a token FCM no longer knows, so it can be forgotten', function (int $status, array $error) {
    fakeFcm($status, ['error' => $error]);

    expect((new FcmPushSender)->send('old-token', $this->message))->toBe(PushResult::TokenNoLongerValid);
})->with([
    'unregistered' => [404, ['code' => 404, 'status' => 'NOT_FOUND', 'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']]]],
    'invalid' => [400, ['code' => 400, 'status' => 'INVALID_ARGUMENT', 'message' => 'The registration token is not a valid FCM registration token']],
]);

it('throws when FCM is down or refuses for another reason', function (int $status, array $answer, string $message) {
    fakeFcm($status, $answer);

    expect(fn () => (new FcmPushSender)->send('phone-token-1', $this->message))
        ->toThrow(PushNotSent::class, $message);
})->with([
    'down' => [503, [], 'not answering'],
    'too many' => [429, ['error' => ['status' => 'RESOURCE_EXHAUSTED']], 'not answering'],
    'forbidden' => [403, ['error' => ['status' => 'PERMISSION_DENIED', 'message' => 'SenderId mismatch']], 'SenderId mismatch'],
]);

it('says what is missing when push is not set up', function () {
    config(['services.fcm.credentials' => '/nowhere/key.json']);

    expect(fn () => (new FcmPushSender)->send('phone-token-1', $this->message))
        ->toThrow(PushNotSent::class, 'FCM_CREDENTIALS');
});
