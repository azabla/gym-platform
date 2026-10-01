<?php

declare(strict_types=1);

namespace App\Domains\Shared\Money\Exceptions;

use App\Domains\Shared\Money\Currency;
use LogicException;

final class CurrencyMismatch extends LogicException
{
    public static function between(Currency $a, Currency $b): self
    {
        return new self("Cannot combine {$a->value} with {$b->value}.");
    }
}
