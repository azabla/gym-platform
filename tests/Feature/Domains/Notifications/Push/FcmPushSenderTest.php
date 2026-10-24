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

    Http::assertSent(function (Request $request) {
        if ($request->url() !== 'https://oauth2.googleapis.com/token') {
            return false;
        }

        [$header, $claims, $signature] = explode('.', $request['assertion']);
        $decode = fn (string $part) => base64_decode(strtr($part, '-_', '+/'));
        $payload = json_decode($decode($claims), true);

        return $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
            && json_decode($decode($header), true) === ['alg' => 'RS256', 'typ' => 'JWT']
            && $payload['iss'] === 'push@volt-prod.iam.gserviceaccount.com'
            && $payload['scope'] === 'https://www.googleapis.com/auth/firebase.messaging'
            && $payload['aud'] === 'https://oauth2.googleapis.com/token'
            && openssl_verify("{$header}.{$claims}", $decode($signature), $this->publicKey, OPENSSL_ALGO_SHA256) === 1;
    });
    Http::assertSent(fn (Request $request) => $request->url() === 'https://fcm.googleapis.com/v1/projects/volt-prod/messages:send'
        && $request->hasHeader('Authorization', 'Bearer ya29.fresh')
        && $request['message'] === [
            'token' => 'phone-token-1',
            'notification' => ['title' => 'Payment received', 'body' => 'Your payment at Demo Gym was recorded.'],
            'data' => ['type' => 'payment_succeeded', 'payment_id' => '12'],
            'android' => ['priority' => 'HIGH'],
        ]);
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
