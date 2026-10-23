<?php

declare(strict_types=1);

namespace App\Domains\Billing\Chapa;

use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Shared\Money\Currency;
use App\Domains\Shared\Money\Exceptions\InvalidMoneyAmount;
use App\Domains\Shared\Money\Money;

/**
 * How Chapa says a transaction stands, from its verify answer. Anything
 * missing or unexpected reads as not succeeded, so nothing is recorded by
 * mistake. See ADR 0009.
 */
final readonly class ChapaTransaction
{
    private function __construct(
        public bool $succeeded,
        public ?string $txRef,
        public ?Money $amount,
        public ?string $reference,
    ) {}

    /**
     * Read the data of Chapa's verify answer.
     *
     * @param  array<mixed>  $data
     */
    public static function fromChapa(array $data): self
    {
        return new self(
            succeeded: ($data['status'] ?? null) === 'success',
            txRef: self::text($data['tx_ref'] ?? null),
            amount: self::money($data['amount'] ?? null, $data['currency'] ?? null),
            reference: self::text($data['reference'] ?? null),
        );
    }

    /**
     * A transaction Chapa doesn't know as paid, because the member hasn't paid yet.
     */
    public static function notPaidYet(): self
    {
        return new self(succeeded: false, txRef: null, amount: null, reference: null);
    }

    /**
     * Whether this is the payment the link asked for: it succeeded, for the
     * link's tx_ref and exactly its amount and currency, with Chapa's reference.
     */
    public function pays(PaymentLink $paymentLink): bool
    {
        return $this->succeeded
            && $this->txRef === $paymentLink->tx_ref
            && $this->amount !== null
            && $this->amount->equals($paymentLink->amount)
            && $this->reference !== null;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Chapa sends the amount as text or as a number, such as "1500.00" or 1500.
     */
    private static function money(mixed $amount, mixed $currencyCode): ?Money
    {
        $currency = is_string($currencyCode) ? Currency::tryFrom($currencyCode) : null;

        if ($currency === null || ! (is_string($amount) || is_int($amount) || is_float($amount))) {
            return null;
        }

        try {
            return Money::of((string) $amount, $currency);
        } catch (InvalidMoneyAmount) {
            return null;
        }
    }
}
