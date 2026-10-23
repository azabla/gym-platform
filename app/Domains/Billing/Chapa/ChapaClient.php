<?php

declare(strict_types=1);

namespace App\Domains\Billing\Chapa;

use App\Domains\Billing\Chapa\Exceptions\ChapaRequestFailed;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Talks to Chapa's API with a gym's own secret key, through Laravel's HTTP
 * client. Chapa's address comes from config('services.chapa.base_url'), so
 * tests and other environments can point it elsewhere. See ADR 0009.
 */
final class ChapaClient
{
    private const TIMEOUT_SECONDS = 15;

    /**
     * Check that Chapa accepts the secret key, by asking for its list of
     * banks, which needs a valid key.
     *
     * @throws ChapaRequestFailed
     */
    public function checkKey(string $secretKey): void
    {
        $this->send($secretKey, fn (PendingRequest $chapa): Response => $chapa->get('banks'));
    }

    /**
     * Send one request to Chapa. A 4xx answer is Chapa refusing it; no answer,
     * or a 5xx answer, means Chapa is unavailable.
     *
     * @param  Closure(PendingRequest): Response  $request
     *
     * @throws ChapaRequestFailed
     */
    private function send(string $secretKey, Closure $request): Response
    {
        $chapa = Http::baseUrl((string) config('services.chapa.base_url'))
            ->withToken($secretKey)
            ->acceptJson()
            ->timeout(self::TIMEOUT_SECONDS);

        try {
            $response = $request($chapa);
        } catch (ConnectionException) {
            throw ChapaRequestFailed::unavailable();
        }

        if ($response->clientError()) {
            throw ChapaRequestFailed::refused($this->reasonGiven($response));
        }

        if ($response->failed()) {
            throw ChapaRequestFailed::unavailable();
        }

        return $response;
    }

    /**
     * Chapa's message, which is text, or the validation errors per field.
     */
    private function reasonGiven(Response $response): string
    {
        $message = $response->json('message');

        if (is_array($message)) {
            return implode(' ', array_map(
                fn (mixed $errors): string => implode(' ', (array) $errors),
                $message,
            ));
        }

        return is_string($message) && $message !== '' ? $message : 'no reason given.';
    }
}
