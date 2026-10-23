<?php

declare(strict_types=1);

use App\Domains\Memberships\Discount\Discount;
use App\Domains\Memberships\Discount\Exceptions\InvalidDiscount;
use App\Domains\Shared\Money\Money;

it('takes an amount or a percentage off the price', function (string $discount, string $priceToPay) {
    expect(Discount::parse($discount, 'Student')->appliedTo(Money::of('2,500')))->toEqual(Money::of($priceToPay));
})->with([
    'an amount' => ['500', '2,000'],
    'an amount with santim' => ['1,500.50', '999.50'],
    'a percentage' => ['10%', '2,250'],
    'a percentage with decimals' => ['12.5%', '2,187.50'],
    'a space before the percent sign' => ['10 %', '2,250'],
    'everything' => ['100%', '0'],
]);

it('rounds a percentage to the santim', function () {
    expect(Discount::parse('10%', 'Student')->appliedTo(Money::of('1,234.55')))->toEqual(Money::of('1,111.09'));
});

it('keeps its reason', function () {
    expect(Discount::parse('10%', 'Student')->reason)->toBe('Student');
});

it('rejects input that is not a discount', function (string $input) {
    Discount::parse($input, 'Student');
})->throws(InvalidDiscount::class)->with([
    'empty' => [''],
    'words' => ['ten percent'],
    'zero' => ['0'],
    'zero percent' => ['0%'],
    'a negative amount' => ['-500'],
    'a negative percentage' => ['-10%'],
    'more than 100%' => ['101%'],
    'three decimals in a percentage' => ['12.345%'],
]);

it('refuses a discount that is more than the price', function () {
    Discount::parse('3,000', 'Student')->appliedTo(Money::of('2,500'));
})->throws(InvalidDiscount::class, 'more than the price of 2,500.00 ETB');

it('refuses a percentage that takes nothing off', function () {
    Discount::parse('0.01%', 'Student')->appliedTo(Money::of('10'));
})->throws(InvalidDiscount::class, 'takes nothing off');
