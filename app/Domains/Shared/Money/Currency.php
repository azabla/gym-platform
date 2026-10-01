<?php

declare(strict_types=1);

namespace App\Domains\Shared\Money;

/**
 * Supported currencies.
 *
 * ETB is the business currency. USD exists so the currency-mismatch guard in
 * Money is real (and testable) rather than theoretical. Add cases only when
 * the business actually needs them.
 */
enum Currency: string
{
    case ETB = 'ETB';
    case USD = 'USD';

    /**
     * How many decimal places the currency uses (ETB: 1 birr = 100 santim).
     */
    public function decimals(): int
    {
        return match ($this) {
            self::ETB, self::USD => 2,
        };
    }

    /**
     * How many minor units make one major unit (100 for 2 decimals).
     */
    public function minorPerMajor(): int
    {
        return 10 ** $this->decimals();
    }
}
