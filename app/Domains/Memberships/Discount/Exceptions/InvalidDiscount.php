<?php

declare(strict_types=1);

namespace App\Domains\Memberships\Discount\Exceptions;

use App\Domains\Shared\Money\Money;
use InvalidArgumentException;

/**
 * A discount that can't be read or applied. The message is in English; the
 * staff panel shows it in the staff member's language from its text and
 * values: __($exception->text, $exception->values) (ADR 0041).
 */
final class InvalidDiscount extends InvalidArgumentException
{
    /**
     * @param  array<string, string>  $values
     */
    private function __construct(public readonly string $text, public readonly array $values)
    {
        $placeholders = array_map(fn (string $name): string => ":{$name}", array_keys($values));

        parent::__construct(strtr($text, array_combine($placeholders, $values)));
    }

    public static function cannotParse(string $input): self
    {
        return new self('Cannot read [:input] as a discount. Expected an amount like 500 or a percentage like 10%.', ['input' => $input]);
    }

    public static function notMoreThanZero(string $input): self
    {
        return new self('A discount must be more than zero, not [:input].', ['input' => $input]);
    }

    public static function moreThanAHundredPercent(string $input): self
    {
        return new self('A discount can be at most 100%, not [:input].', ['input' => $input]);
    }

    public static function moreThanThePrice(Money $price): self
    {
        return new self('The discount is more than the price of :price.', ['price' => $price->format()]);
    }

    public static function nothingOff(Money $price): self
    {
        return new self('The discount takes nothing off the price of :price.', ['price' => $price->format()]);
    }
}
