<?php

declare(strict_types=1);

use App\Domains\PlatformBilling\GymCharge;
use App\Domains\Shared\Money\Money;

it('charges the first branch, each extra branch, for each month, less the discount, with the VAT inside', function (int $branches, int $months, int $discount, string $total, string $vat) {
    $charge = new GymCharge(Money::of('2,000'), Money::of('500'), $branches, $months, $discount);

    expect($charge->total->toDecimal())->toBe($total)
        ->and($charge->vat->toDecimal())->toBe($vat)
        ->and($charge->beforeDiscount->subtract($charge->discount)->equals($charge->total))->toBeTrue();
})->with([
    'one branch, a month' => [1, 1, 0, '2000.00', '260.87'],
    'three branches, a month' => [3, 1, 0, '3000.00', '391.30'],
    'one branch, 3 months, 5% off' => [1, 3, 5, '5700.00', '743.48'],
    'three branches, a year, 10% off' => [3, 12, 10, '32400.00', '4226.09'],
]);

it('charges nothing more for extra branches when they are free', function () {
    $charge = new GymCharge(Money::of('1,500'), Money::zero(), 4, 1, 0);

    expect($charge->total->toDecimal())->toBe('1500.00')
        ->and($charge->extraBranches())->toBe(3);
});

it('refuses what an invoice can\'t be', function (int $branches, int $months, int $discount) {
    new GymCharge(Money::of('2,000'), Money::of('500'), $branches, $months, $discount);
})->with([
    'no branch' => [0, 1, 0],
    '2 months' => [1, 2, 0],
    'over 50% off' => [1, 12, 51],
])->throws(InvalidArgumentException::class);
