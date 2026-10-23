<?php

namespace App\Casts;

use App\Domains\Shared\Money\Currency;
use App\Domains\Shared\Money\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Stores a Money in two columns, an integer amount of minor units and a
 * currency code, as ADR 0001 requires. For example, a "price" attribute cast
 * with AsMoney::class.':price_minor,currency'.
 *
 * @implements CastsAttributes<Money, Money>
 */
class AsMoney implements CastsAttributes
{
    public function __construct(
        private string $minorUnitsColumn,
        private string $currencyColumn = 'currency',
    ) {}

    /**
     * Cast the given value.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if (! isset($attributes[$this->minorUnitsColumn], $attributes[$this->currencyColumn])) {
            return null;
        }

        return Money::ofMinor(
            (int) $attributes[$this->minorUnitsColumn],
            Currency::from((string) $attributes[$this->currencyColumn]),
        );
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, int|string>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (! $value instanceof Money) {
            throw new InvalidArgumentException("The {$key} attribute must be a Money.");
        }

        return [
            $this->minorUnitsColumn => $value->minor,
            $this->currencyColumn => $value->currency->value,
        ];
    }
}
