<?php

declare(strict_types=1);

namespace App\Domains\Billing\Chapa;

use App\Domains\Billing\Chapa\Exceptions\ChapaRequestFailed;
use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Shared\PhoneNumber\PhoneNumber;
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

    /** The longest page title and description Chapa accepts. */
    private const TITLE_LENGTH = 16;

    private const DESCRIPTION_LENGTH = 50;

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
     * Start a checkout for the payment link on Chapa's payment page, and give
     * its address. The page shows the gym's name and the plan, and Chapa calls
     * the callback URL once the member has paid.
     *
     * @throws ChapaRequestFailed
     */
    public function startCheckout(PaymentLink $link, string $secretKey, string $callbackUrl): string
    {
        $member = $link->membership->member;
        [$firstName, $lastName] = $this->firstAndLastName($member->full_name);

        $details = array_filter([
            'amount' => $link->amount->toDecimal(),
            'currency' => $link->amount->currency->value,
            'tx_ref' => $link->tx_ref,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phone_number' => PhoneNumber::of($member->phone)->ethiopianNational(),
            'email' => $member->email,
            'callback_url' => $callbackUrl,
            'customization' => [
                'title' => $this->textChapaAccepts($link->organization->name, self::TITLE_LENGTH, 'Gym'),
                'description' => $this->textChapaAccepts("{$link->membership->plan->name} membership", self::DESCRIPTION_LENGTH, 'Membership'),
            ],
        ], fn (mixed $detail): bool => $detail !== null);

        $response = $this->send($secretKey, fn (PendingRequest $chapa): Response => $chapa->post('transaction/initialize', $details));
        $checkoutUrl = $response->json('data.checkout_url');

        if (! is_string($checkoutUrl) || $checkoutUrl === '') {
            throw ChapaRequestFailed::unexpectedAnswer();
        }

        return $checkoutUrl;
    }

    /**
     * A one-word name is sent as both the first and the last name, because
     * Chapa asks for both.
     *
     * @return array{0: string, 1: string}
     */
    private function firstAndLastName(string $fullName): array
    {
        $names = preg_split('/\s+/', trim($fullName), 2) ?: [];
        $firstName = $names[0] ?? '';

        return [$firstName, $names[1] ?? $firstName];
    }

    /**
     * Chapa's page title and description take only letters, digits, spaces,
     * dots, hyphens and underscores, and only so many of them.
     */
    private function textChapaAccepts(string $text, int $length, string $whenNothingIsLeft): string
    {
        $accepted = (string) preg_replace('/[^A-Za-z0-9 ._-]/', '', $text);
        $accepted = rtrim(substr(trim((string) preg_replace('/\s+/', ' ', $accepted)), 0, $length));

        return $accepted === '' ? $whenNothingIsLeft : $accepted;
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
