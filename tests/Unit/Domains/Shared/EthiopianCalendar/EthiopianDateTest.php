<?php

declare(strict_types=1);

use App\Domains\Shared\EthiopianCalendar\EthiopianDate;

it('turns Gregorian dates into Ethiopian ones and back', function (string $gregorian, int $year, int $month, int $day) {
    $ethiopian = EthiopianDate::fromGregorian($gregorian);

    expect([$ethiopian->year, $ethiopian->month, $ethiopian->day])->toBe([$year, $month, $day])
        ->and(EthiopianDate::of($year, $month, $day)->toGregorian())->toBe($gregorian);
})->with([
    'Enkutatash 2025' => ['2025-09-11', 2018, 1, 1],
    'Meskel 2025' => ['2025-09-27', 2018, 1, 17],
    'Enkutatash after a leap year' => ['2023-09-12', 2016, 1, 1],
    'Meskel after a leap year' => ['2023-09-28', 2016, 1, 17],
    'Timket 2026' => ['2026-01-19', 2018, 5, 11],
    'Timket in a Gregorian leap year' => ['2024-01-20', 2016, 5, 11],
    'Adwa 2026' => ['2026-03-02', 2018, 6, 23],
    'Pagume 6 of a leap year' => ['2023-09-11', 2015, 13, 6],
    'Pagume 5' => ['2025-09-10', 2017, 13, 5],
]);

it('keeps a holiday on its Ethiopian date from one year to the next', function () {
    $timket = EthiopianDate::fromGregorian('2026-01-19');

    expect($timket->dayAndMonth())->toBe('Tir 11')
        ->and($timket->addYears(1)->toGregorian())->toBe('2027-01-19')
        ->and($timket->addYears(2)->toGregorian())->toBe('2028-01-20');
});

it('moves Pagume 6 to Pagume 5 in a year without it', function () {
    expect(EthiopianDate::of(2015, 13, 6)->addYears(1)->dayAndMonth())->toBe('Pagume 5');
});

it('refuses a day the month doesn\'t have, and a date it can\'t read', function () {
    expect(fn () => EthiopianDate::of(2018, 13, 6))->toThrow(InvalidArgumentException::class)
        ->and(fn () => EthiopianDate::of(2018, 5, 31))->toThrow(InvalidArgumentException::class)
        ->and(fn () => EthiopianDate::fromGregorian('2026-02-30'))->toThrow(InvalidArgumentException::class);
});
