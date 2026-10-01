<?php

declare(strict_types=1);

namespace App\Domains\Shared\Money;

use App\Domains\Shared\Money\Exceptions\CurrencyMismatch;
use App\Domains\Shared\Money\Exceptions\InvalidMoneyAmount;
use JsonSerializable;
use Stringable;

/**
 * An immutable amount of money, stored as an integer number of minor units
 * (santim for ETB). No floating point is used anywhere in this class.
 *
 * See docs/adr/0001-money-as-integer-minor-units.md for the reasoning.
 */
final readonly class Money implements JsonSerializable, Stringable
{
    /** Largest number of major-unit digits accepted when parsing (fits safely in a 64-bit int). */
    private const MAX_MAJOR_DIGITS = 15;

    private function __construct(
        public int $minor,
        public Currency $currency,
    ) {}

    // ------------------------------------------------------------------
    // Creating money
    // ------------------------------------------------------------------

    /** Create from minor units: Money::ofMinor(150050) is 1,500.50 ETB. */
    public static function ofMinor(int $minor, Currency $currency = Currency::ETB): self
    {
        return new self($minor, $currency);
    }

    public static function zero(Currency $currency = Currency::ETB): self
    {
        return new self(0, $currency);
    }

    /**
     * Parse a human decimal string: "1500", "1500.5", "1,500.50", "-20.00".
     *
     * Accepts a string (never a float) because a float has already lost
     * precision before it reaches us.
     */
    public static function of(string $amount, Currency $currency = Currency::ETB): self
    {
        $decimals = $currency->decimals();
        $pattern = '/^(-)?(\d{1,3}(?:,\d{3})+|\d+)(?:\.(\d{1,'.$decimals.'}))?$/';

        if (preg_match($pattern, trim($amount), $matches) !== 1) {
            throw InvalidMoneyAmount::cannotParse($amount);
        }

        $majorDigits = str_replace(',', '', $matches[2]);

        if (strlen(ltrim($majorDigits, '0')) > self::MAX_MAJOR_DIGITS) {
            throw InvalidMoneyAmount::cannotParse($amount);
        }

        $fractionDigits = str_pad($matches[3] ?? '', $decimals, '0');
        $minor = ((int) $majorDigits) * $currency->minorPerMajor() + (int) $fractionDigits;

        return new self($matches[1] === '-' ? -$minor : $minor, $currency);
    }

    // ------------------------------------------------------------------
    // Arithmetic (always returns a NEW instance)
    // ------------------------------------------------------------------

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor - $other->minor, $this->currency);
    }

    /** Multiply by a whole number, e.g. quantity on an invoice line. */
    public function multiply(int $factor): self
    {
        return new self($this->minor * $factor, $this->currency);
    }

    public function negate(): self
    {
        return new self(-$this->minor, $this->currency);
    }

    /**
     * A percentage of this amount, expressed in basis points
     * (1 basis point = 0.01%, so 1500 = 15%, 1250 = 12.5%).
     *
     * Rounds half away from zero to the nearest minor unit.
     */
    public function percentage(int $basisPoints): self
    {
        $product = $this->minor * $basisPoints;
        $rounded = intdiv(abs($product) + 5_000, 10_000);

        return new self($product < 0 ? -$rounded : $rounded, $this->currency);
    }

    /**
     * Split this amount by ratios without losing or inventing a single santim.
     *
     * Money::of('100')->allocate(1, 1, 1) gives 33.34, 33.33, 33.33.
     * The leftover minor units go to the earliest parts with a non-zero ratio.
     *
     * @return list<self>
     */
    public function allocate(int ...$ratios): array
    {
        $ratios = array_values($ratios);

        if ($ratios === [] || min($ratios) < 0 || array_sum($ratios) === 0) {
            throw InvalidMoneyAmount::invalidRatios();
        }

        $total = array_sum($ratios);
        $shares = [];

        foreach ($ratios as $ratio) {
            $shares[] = intdiv($this->minor * $ratio, $total);
        }

        $remainder = $this->minor - array_sum($shares);
        $step = $remainder <=> 0;

        foreach ($ratios as $index => $ratio) {
            if ($remainder === 0) {
                break;
            }

            if ($ratio === 0) {
                continue;
            }

            $shares[$index] += $step;
            $remainder -= $step;
        }

        return array_map(fn (int $minor): self => new self($minor, $this->currency), $shares);
    }

    // ------------------------------------------------------------------
    // Comparison
    // ------------------------------------------------------------------

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->minor === $other->minor;
    }

    public function isGreaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minor > $other->minor;
    }

    public function isLessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minor < $other->minor;
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    // ------------------------------------------------------------------
    // Output
    // ------------------------------------------------------------------

    /** Plain decimal string for forms and exports: "1500.50". */
    public function toDecimal(): string
    {
        [$sign, $major, $fraction] = $this->parts();

        return "{$sign}{$major}.{$fraction}";
    }

    /** Human display: "1,500.50 ETB". */
    public function format(): string
    {
        [$sign, $major, $fraction] = $this->parts();

        return $sign.number_format($major).".{$fraction} {$this->currency->value}";
    }

    /** @return array{amount_minor: int, currency: string, formatted: string} */
    public function jsonSerialize(): array
    {
        return [
            'amount_minor' => $this->minor,
            'currency' => $this->currency->value,
            'formatted' => $this->format(),
        ];
    }

    public function __toString(): string
    {
        return $this->format();
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw CurrencyMismatch::between($this->currency, $other->currency);
        }
    }

    /** @return array{0: string, 1: int, 2: string} sign, major units, zero-padded fraction */
    private function parts(): array
    {
        $absolute = abs($this->minor);
        $perMajor = $this->currency->minorPerMajor();

        return [
            $this->minor < 0 ? '-' : '',
            intdiv($absolute, $perMajor),
            str_pad((string) ($absolute % $perMajor), $this->currency->decimals(), '0', STR_PAD_LEFT),
        ];
    }
}
