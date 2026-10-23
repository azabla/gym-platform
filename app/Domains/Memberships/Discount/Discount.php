<?php

declare(strict_types=1);

namespace App\Domains\Memberships\Discount;

use App\Domains\Memberships\Discount\Exceptions\InvalidDiscount;
use App\Domains\Shared\Money\Currency;
use App\Domains\Shared\Money\Exceptions\InvalidMoneyAmount;
use App\Domains\Shared\Money\Money;

/**
 * A reduction of a plan's price at sale, with the reason for it: an amount off
 * ("500") or a percentage off ("10%", up to two decimals). See ADR 0007.
 */
final readonly class Discount
{
    private function __construct(
        private ?Money $amountOff,
        private ?int $basisPointsOff,
        public string $reason,
    ) {}

    /**
     * Read a discount the way staff type it: "500" or "1,500.50" for an amount
     * off, "10%" or "12.5%" for a percentage off.
     */
    public static function parse(string $input, string $reason, Currency $currency = Currency::ETB): self
    {
        $compact = trim($input);

        if (preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?\s*%$/', $compact, $matches) === 1) {
            $basisPoints = (int) $matches[1] * 100 + (int) str_pad($matches[2] ?? '', 2, '0');

            return match (true) {
                $basisPoints === 0 => throw InvalidDiscount::notMoreThanZero($input),
                $basisPoints > 10_000 => throw InvalidDiscount::moreThanAHundredPercent($input),
                default => new self(null, $basisPoints, $reason),
            };
        }

        try {
            $amountOff = Money::of($compact, $currency);
        } catch (InvalidMoneyAmount) {
            throw InvalidDiscount::cannotParse($input);
        }

        if (! $amountOff->isPositive()) {
            throw InvalidDiscount::notMoreThanZero($input);
        }

        return new self($amountOff, null, $reason);
    }

    /**
     * The price to pay after this discount. A percentage is rounded to the
     * minor unit (half away from zero). A discount that is more than the price,
     * or that takes nothing off it, is refused.
     */
    public function appliedTo(Money $price): Money
    {
        $amountOff = $this->basisPointsOff === null ? $this->amountOff : $price->percentage($this->basisPointsOff);

        if ($amountOff->isGreaterThan($price)) {
            throw InvalidDiscount::moreThanThePrice($price);
        }

        if ($amountOff->isZero()) {
            throw InvalidDiscount::nothingOff($price);
        }

        return $price->subtract($amountOff);
    }
}
