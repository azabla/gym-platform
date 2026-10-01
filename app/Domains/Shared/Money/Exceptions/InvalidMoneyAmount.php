<?php

declare(strict_types=1);

namespace App\Domains\Shared\Money\Exceptions;

use InvalidArgumentException;

final class InvalidMoneyAmount extends InvalidArgumentException
{
    public static function cannotParse(string $input): self
    {
        return new self("Cannot parse [{$input}] as a money amount. Expected a value like 1500 or 1,500.50.");
    }

    public static function invalidRatios(): self
    {
        return new self('Allocation ratios must be non-negative and contain at least one positive value.');
    }
}
