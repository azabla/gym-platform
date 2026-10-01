<?php

declare(strict_types=1);

use App\Domains\Shared\Money\Currency;
use App\Domains\Shared\Money\Exceptions\CurrencyMismatch;
use App\Domains\Shared\Money\Exceptions\InvalidMoneyAmount;
use App\Domains\Shared\Money\Money;

describe('creating money', function () {
    it('parses decimal strings into santim without floating point', function (string $input, int $expectedMinor) {
        expect(Money::of($input)->minor)->toBe($expectedMinor);
    })->with([
        'whole birr' => ['1500', 150000],
        'two decimals' => ['1500.50', 150050],
        'one decimal is padded' => ['0.5', 50],
        'thousand separators' => ['1,500.50', 150050],
        'large amount' => ['1,234,567.89', 123456789],
        'negative' => ['-20.05', -2005],
        'surrounding spaces' => ['  300  ', 30000],
        'zero' => ['0', 0],
    ]);

    it('rejects input it cannot parse exactly', function (string $input) {
        Money::of($input);
    })->throws(InvalidMoneyAmount::class)->with([
        'empty' => [''],
        'letters' => ['abc'],
        'too many decimals' => ['1.005'],
        'two dots' => ['1.2.3'],
        'scientific notation' => ['1e3'],
        'badly placed comma' => ['15,00'],
        'currency symbol' => ['ETB 100'],
        'too large' => ['9999999999999999'],
    ]);

    it('defaults to ETB', function () {
        expect(Money::of('10')->currency)->toBe(Currency::ETB)
            ->and(Money::ofMinor(1000)->currency)->toBe(Currency::ETB)
            ->and(Money::zero()->isZero())->toBeTrue();
    });
});

describe('arithmetic', function () {
    it('adds and subtracts', function () {
        $fee = Money::of('1500');
        $locker = Money::of('300');

        expect($fee->add($locker)->minor)->toBe(180000)
            ->and($fee->subtract($locker)->minor)->toBe(120000);
    });

    it('never mutates the original instance', function () {
        $original = Money::of('1500');
        $original->add(Money::of('500'));

        expect($original->minor)->toBe(150000);
    });

    it('refuses to mix currencies', function () {
        Money::of('10', Currency::ETB)->add(Money::of('10', Currency::USD));
    })->throws(CurrencyMismatch::class);

    it('multiplies by a quantity', function () {
        expect(Money::of('25.50')->multiply(3)->minor)->toBe(7650);
    });

    it('negates', function () {
        expect(Money::of('10')->negate()->minor)->toBe(-1000);
    });
});

describe('percentages', function () {
    it('takes a percentage expressed in basis points', function () {
        // 15% of 1,500 ETB = 225 ETB
        expect(Money::of('1500')->percentage(1500)->minor)->toBe(22500);
    });

    it('rounds half away from zero to the nearest santim', function (int $minor, int $basisPoints, int $expected) {
        expect(Money::ofMinor($minor)->percentage($basisPoints)->minor)->toBe($expected);
    })->with([
        '1.5 rounds up to 2' => [3, 5000, 2],
        '0.4999 rounds down to 0' => [1, 4999, 0],
        '-1.5 rounds to -2' => [-3, 5000, -2],
        '12.5% of 99.99' => [9999, 1250, 1250], // 1249.875 → 1250
    ]);
});

describe('allocation', function () {
    it('splits without losing a santim', function () {
        $parts = Money::of('100')->allocate(1, 1, 1);

        expect(array_map(fn (Money $m) => $m->minor, $parts))->toBe([3334, 3333, 3333]);
    });

    it('always sums back to the original amount', function (int $minor, array $ratios) {
        $parts = Money::ofMinor($minor)->allocate(...$ratios);

        expect(array_sum(array_map(fn (Money $m) => $m->minor, $parts)))->toBe($minor);
    })->with([
        [100, [1, 1, 1]],
        [280000, [150000, 30000, 100000]], // one payment across invoice lines
        [1, [1, 1, 1, 1]],
        [-100, [1, 1, 1]],
        [99999, [7, 3]],
    ]);

    it('respects ratios', function () {
        $parts = Money::of('1000')->allocate(70, 30);

        expect($parts[0]->minor)->toBe(70000)
            ->and($parts[1]->minor)->toBe(30000);
    });

    it('gives nothing to a zero ratio', function () {
        $parts = Money::ofMinor(100)->allocate(0, 1, 1);

        expect(array_map(fn (Money $m) => $m->minor, $parts))->toBe([0, 50, 50]);
    });

    it('rejects invalid ratios', function (array $ratios) {
        Money::of('100')->allocate(...$ratios);
    })->throws(InvalidMoneyAmount::class)->with([
        'no ratios' => [[]],
        'all zero' => [[0, 0]],
        'negative' => [[1, -1]],
    ]);
});

describe('comparison', function () {
    it('compares amounts', function () {
        $small = Money::of('10');
        $big = Money::of('20');

        expect($big->isGreaterThan($small))->toBeTrue()
            ->and($small->isLessThan($big))->toBeTrue()
            ->and($small->equals(Money::of('10.00')))->toBeTrue()
            ->and($small->equals($big))->toBeFalse();
    });

    it('treats different currencies as not equal', function () {
        expect(Money::of('10', Currency::ETB)->equals(Money::of('10', Currency::USD)))->toBeFalse();
    });

    it('reports its sign', function () {
        expect(Money::of('1')->isPositive())->toBeTrue()
            ->and(Money::of('-1')->isNegative())->toBeTrue()
            ->and(Money::zero()->isZero())->toBeTrue();
    });
});

describe('output', function () {
    it('formats for humans', function (int $minor, string $expected) {
        expect(Money::ofMinor($minor)->format())->toBe($expected);
    })->with([
        [150050, '1,500.50 ETB'],
        [5, '0.05 ETB'],
        [-5, '-0.05 ETB'],
        [0, '0.00 ETB'],
        [123456789, '1,234,567.89 ETB'],
    ]);

    it('produces a plain decimal for forms', function () {
        expect(Money::ofMinor(150050)->toDecimal())->toBe('1500.50')
            ->and(Money::ofMinor(-5)->toDecimal())->toBe('-0.05');
    });

    it('serializes to JSON without floats', function () {
        expect(json_encode(Money::of('1500')))
            ->toBe('{"amount_minor":150000,"currency":"ETB","formatted":"1,500.00 ETB"}');
    });

    it('round-trips through toDecimal', function (string $input) {
        $money = Money::of($input);

        expect(Money::of($money->toDecimal())->equals($money))->toBeTrue();
    })->with(['0.01', '1500.50', '-99.99', '1234567.89']);
});
