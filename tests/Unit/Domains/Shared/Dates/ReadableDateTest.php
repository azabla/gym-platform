<?php

declare(strict_types=1);

use App\Domains\Shared\Dates\ReadableDate;
use Carbon\CarbonImmutable;

it('writes a date in Amharic with the Ethiopian date first and the Gregorian beside it', function () {
    $date = CarbonImmutable::parse('2026-11-27 14:05');

    expect(ReadableDate::long($date, 'am'))->toBe('ኅዳር 18 ቀን 2019 ዓ.ም. (ኖቬምበር 27, 2026)')
        ->and(ReadableDate::short($date, 'am'))->toBe('ኅዳር 18, 2019 (27/11/2026)')
        ->and(ReadableDate::shortWithTime($date, 'am'))->toBe('ኅዳር 18, 2019 (27/11/2026), 14:05')
        ->and(ReadableDate::dayOfWeek($date, 'am'))->toBe('ዓርብ፣ ኅዳር 18 (27/11)')
        ->and(ReadableDate::ethiopian($date, 'am'))->toBe('ኅዳር 18 ቀን 2019 ዓ.ም.')
        ->and(ReadableDate::month($date, 'am'))->toBe('ኖቬምበር 2026')
        ->and(ReadableDate::weekday(7, 'am'))->toBe('እሁድ');
});

it('keeps the Gregorian date alone in English', function () {
    $date = CarbonImmutable::parse('2026-11-27 14:05');

    expect(ReadableDate::long($date, 'en'))->toBe('27 November 2026')
        ->and(ReadableDate::short($date, 'en'))->toBe('27 Nov 2026')
        ->and(ReadableDate::shortWithTime($date, 'en'))->toBe('27 Nov 2026, 14:05')
        ->and(ReadableDate::dayOfWeek($date, 'en'))->toBe('Fri 27 Nov')
        ->and(ReadableDate::month($date, 'en'))->toBe('Nov 2026')
        ->and(ReadableDate::weekday(1, 'en'))->toBe('Monday');
});

it('names Pagume and the Ethiopian new year in Amharic', function (string $gregorian, string $amharic) {
    expect(ReadableDate::short(CarbonImmutable::parse($gregorian), 'am'))->toBe($amharic);
})->with([
    'Pagume 5' => ['2026-09-10', 'ጳጉሜን 5, 2018 (10/09/2026)'],
    'the new year' => ['2026-09-11', 'መስከረም 1, 2019 (11/09/2026)'],
    'Pagume 6 of a leap year' => ['2027-09-11', 'ጳጉሜን 6, 2019 (11/09/2027)'],
]);
