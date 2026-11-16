<?php

declare(strict_types=1);

namespace App\Domains\Memberships\Pricing;

use App\Domains\Memberships\Discount\Discount;
use App\Domains\Memberships\Models\DiscountGroup;
use App\Domains\Memberships\Models\PromoCode;
use App\Domains\Shared\Money\Money;

/**
 * What a member pays for a plan, and why: the plan's price, or less through
 * their discount group or a promo code, never both (ADR 0032).
 */
final readonly class PriceChoice
{
    private function __construct(
        public Money $listPrice,
        public Money $price,
        public ?PromoCode $promoCode,
        public ?DiscountGroup $discountGroup,
    ) {}

    public static function full(Money $listPrice): self
    {
        return new self($listPrice, $listPrice, null, null);
    }

    public static function withCode(Money $listPrice, Money $price, PromoCode $promoCode): self
    {
        return new self($listPrice, $price, $promoCode, null);
    }

    public static function withGroup(Money $listPrice, Money $price, DiscountGroup $discountGroup): self
    {
        return new self($listPrice, $price, null, $discountGroup);
    }

    public function isDiscounted(): bool
    {
        return $this->promoCode !== null || $this->discountGroup !== null;
    }

    /**
     * The discount that gives the price, with its reason, if any.
     */
    public function discount(): ?Discount
    {
        return $this->promoCode?->discount() ?? $this->discountGroup?->discount();
    }
}
