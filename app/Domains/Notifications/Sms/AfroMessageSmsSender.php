<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Sms;

use App\Domains\Notifications\Sms\Exceptions\SmsNotSent;
use App\Domains\Shared\PhoneNumber\PhoneNumber;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Sends messages through AfroMessage, with the platform's own account: its
 * API token, and optionally its identifier ID (`from`) and approved sender
 * name (`sender`), from config('services.afromessage'). See ADR 0010.
 */
final class AfroMessageSmsSender implements SmsSender
{
    private const TIMEOUT_SECONDS = 15;

    public function send(PhoneNumber $to, string $message): void
    {
        $details = array_filter([
            'from' => config('services.afromessage.identifier_id'),
            'sender' => config('services.afromessage.sender_name'),
            'to' => $to->e164,
            'message' => $message,
        ], fn (mixed $detail): bool => $detail !== null && $detail !== '');

        try {
            $response = Http::baseUrl((string) config('services.afromessage.base_url'))
                ->withToken((string) config('services.afromessage.token'))
                ->acceptJson()
                ->timeout(self::TIMEOUT_SECONDS)
                ->post('send', $details);
        } catch (ConnectionException) {
            throw SmsNotSent::unavailable();
        }

        if ($response->serverError()) {
            throw SmsNotSent::unavailable();
        }

        if ($response->json('acknowledge') !== 'success') {
            throw SmsNotSent::refused($this->reasonGiven($response));
        }
    }

    /**
     * AfroMessage's errors, which come as a list of messages.
     */
    private function reasonGiven(Response $response): string
    {
        $errors = array_filter((array) $response->json('response.errors'), 'is_string');

        return $errors === [] ? "no reason given (status {$response->status()})." : implode(' ', $errors);
    }
}
