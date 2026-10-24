<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Push;

use App\Domains\Notifications\Push\Exceptions\PushNotSent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Sends pushes through Firebase Cloud Messaging's HTTP v1 API, as the
 * platform's Firebase project: FCM_PROJECT_ID, and the service account key
 * at FCM_CREDENTIALS, kept out of git. The service account signs a token
 * request (a JWT), and Google's access token is reused for 55 minutes.
 * See ADR 0012.
 */
final class FcmPushSender implements PushSender
{
    private const TIMEOUT_SECONDS = 15;

    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const ACCESS_TOKEN_CACHE_KEY = 'push.fcm.access-token';

    private const ACCESS_TOKEN_SECONDS = 55 * 60;

    /**
     * FCM's answers meaning the token is no longer any good, so it should be forgotten.
     */
    private const GONE = ['UNREGISTERED', 'NOT_FOUND', 'INVALID_ARGUMENT'];

    public function send(string $deviceToken, PushMessage $message): PushResult
    {
        try {
            $response = Http::withToken($this->accessToken())
                ->acceptJson()
                ->timeout(self::TIMEOUT_SECONDS)
                ->post("https://fcm.googleapis.com/v1/projects/{$this->projectId()}/messages:send", [
                    'message' => [
                        'token' => $deviceToken,
                        'notification' => ['title' => $message->title, 'body' => $message->body],
                        'data' => (object) $message->data,
                        'android' => ['priority' => 'HIGH'],
                    ],
                ]);
        } catch (ConnectionException) {
            throw PushNotSent::unavailable();
        }

        if ($response->successful()) {
            return PushResult::Sent;
        }

        if ($response->status() === 401) {
            Cache::forget(self::ACCESS_TOKEN_CACHE_KEY);
        }

        if ($response->serverError() || $response->status() === 429) {
            throw PushNotSent::unavailable();
        }

        if (array_intersect($this->errorCodes($response), self::GONE) !== []) {
            return PushResult::TokenNoLongerValid;
        }

        throw PushNotSent::refused((string) ($response->json('error.message') ?? "status {$response->status()}"));
    }

    /**
     * Google's access token for the service account, asked for with a JWT
     * the service account signs, and reused until shortly before it expires.
     */
    private function accessToken(): string
    {
        return Cache::remember(self::ACCESS_TOKEN_CACHE_KEY, self::ACCESS_TOKEN_SECONDS, function (): string {
            $credentials = $this->credentials();
            $tokenUri = $credentials['token_uri'];

            try {
                $response = Http::asForm()->acceptJson()->timeout(self::TIMEOUT_SECONDS)->post($tokenUri, [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $this->signedAssertion($credentials['client_email'], $credentials['private_key'], $tokenUri),
                ]);
            } catch (ConnectionException) {
                throw PushNotSent::unavailable();
            }

            $accessToken = $response->json('access_token');

            if (! $response->successful() || ! is_string($accessToken)) {
                throw PushNotSent::misconfigured('Google refused the service account ('.($response->json('error_description') ?? "status {$response->status()}").').');
            }

            return $accessToken;
        });
    }

    private function signedAssertion(string $clientEmail, string $privateKey, string $tokenUri): string
    {
        $now = time();
        $unsigned = $this->base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']))
            .'.'.$this->base64Url((string) json_encode([
                'iss' => $clientEmail,
                'scope' => self::SCOPE,
                'aud' => $tokenUri,
                'iat' => $now,
                'exp' => $now + 3600,
            ]));

        if (! openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw PushNotSent::misconfigured('the service account\'s private key can\'t sign.');
        }

        return $unsigned.'.'.$this->base64Url($signature);
    }

    /**
     * @return array{client_email: string, private_key: string, token_uri: string}
     */
    private function credentials(): array
    {
        $path = (string) config('services.fcm.credentials');
        $path = str_starts_with($path, '/') ? $path : base_path($path);
        $credentials = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($credentials) || ! is_string($credentials['client_email'] ?? null) || ! is_string($credentials['private_key'] ?? null)) {
            throw PushNotSent::misconfigured('FCM_CREDENTIALS must be the path to the service account\'s JSON key.');
        }

        return [
            'client_email' => $credentials['client_email'],
            'private_key' => $credentials['private_key'],
            'token_uri' => is_string($credentials['token_uri'] ?? null) ? $credentials['token_uri'] : 'https://oauth2.googleapis.com/token',
        ];
    }

    private function projectId(): string
    {
        $projectId = (string) config('services.fcm.project_id');

        if ($projectId === '') {
            throw PushNotSent::misconfigured('FCM_PROJECT_ID is not set.');
        }

        return $projectId;
    }

    /**
     * The error codes in FCM's answer: its status, and the FCM error code in its details.
     *
     * @return list<string>
     */
    private function errorCodes(Response $response): array
    {
        $codes = [$response->json('error.status')];

        foreach ((array) $response->json('error.details') as $detail) {
            $codes[] = is_array($detail) ? ($detail['errorCode'] ?? null) : null;
        }

        return array_values(array_filter($codes, 'is_string'));
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
