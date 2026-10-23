<?php

declare(strict_types=1);

namespace App\Domains\Memberships\Discount\Exceptions;

use App\Domains\Shared\Money\Money;
use InvalidArgumentException;

final class InvalidDiscount extends InvalidArgumentException
{
    public static function cannotParse(string $input): self
    {
        return new self("Cannot read [{$input}] as a discount. Expected an amount like 500 or a percentage like 10%.");
    }

    public static function notMoreThanZero(string $input): self
    {
        return new self("A discount must be more than zero, not [{$input}].");
    }

    public static function moreThanAHundredPercent(string $input): self
    {
        return new self("A discount can be at most 100%, not [{$input}].");
    }

    public static function moreThanThePrice(Money $price): self
    {
        return new self("The discount is more than the price of {$price->format()}.");
    }

    public static function nothingOff(Money $price): self
    {
        return new self("The discount takes nothing off the price of {$price->format()}.");
    }
}
