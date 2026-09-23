<?php

declare(strict_types=1);

use App\Exceptions\CurrencyMismatchException;
use App\Support\Money;

it('carries minor units and a currency', function (): void {
    $money = Money::of(12_345, 'EGP');

    expect($money->minor)->toBe(12_345)
        ->and($money->currency)->toBe('EGP');
});

it('normalises the currency code', function (): void {
    expect(Money::of(1, ' egp ')->currency)->toBe('EGP');
});

it('rejects a currency that is not three letters', function (string $currency): void {
    expect(fn () => Money::of(1, $currency))->toThrow(InvalidArgumentException::class);
})->with(['EG', 'EGPP', 'E1P', '']);

it('parses a decimal string without touching a float', function (string $input, int $expected): void {
    expect(Money::fromString($input, 'EGP')->minor)->toBe($expected);
})->with([
    ['0', 0],
    ['123.45', 12_345],
    ['123.4', 12_340],
    ['123', 12_300],
    ['0.29', 29],
    ['-45.67', -4_567],
    ['+7.01', 701],
]);

it('rejects input it cannot parse exactly', function (string $input): void {
    expect(fn () => Money::fromString($input, 'EGP'))->toThrow(InvalidArgumentException::class);
})->with(['123.456', 'abc', '', '1,234.00', '1.2.3']);

it('adds and subtracts without mutating either operand', function (): void {
    $a = Money::of(1_000, 'EGP');
    $b = Money::of(250, 'EGP');

    expect($a->plus($b)->minor)->toBe(1_250)
        ->and($a->minus($b)->minor)->toBe(750)
        ->and($a->minor)->toBe(1_000)
        ->and($b->minor)->toBe(250);
});

it('negates into a new instance', function (): void {
    $money = Money::of(1_000, 'EGP');

    expect($money->negate()->minor)->toBe(-1_000)
        ->and($money->minor)->toBe(1_000);
});

it('refuses to combine two currencies', function (): void {
    $egp = Money::of(1_000, 'EGP');
    $usd = Money::of(1_000, 'USD');

    expect(fn () => $egp->plus($usd))->toThrow(CurrencyMismatchException::class)
        ->and(fn () => $egp->minus($usd))->toThrow(CurrencyMismatchException::class);
});

it('reports sign and zero', function (): void {
    expect(Money::zero('EGP')->isZero())->toBeTrue()
        ->and(Money::of(-1, 'EGP')->isNegative())->toBeTrue()
        ->and(Money::of(-1, 'EGP')->isPositive())->toBeFalse()
        ->and(Money::of(1, 'EGP')->isPositive())->toBeTrue()
        ->and(Money::of(1, 'EGP')->isZero())->toBeFalse();
});

it('compares amount and currency together', function (): void {
    expect(Money::of(100, 'EGP')->equals(Money::of(100, 'EGP')))->toBeTrue()
        ->and(Money::of(100, 'EGP')->equals(Money::of(100, 'USD')))->toBeFalse()
        ->and(Money::of(100, 'EGP')->equals(Money::of(101, 'EGP')))->toBeFalse();
});

it('formats for display only at the edge', function (int $minor, string $expected): void {
    expect(Money::of($minor, 'EGP')->format())->toBe($expected);
})->with([
    [0, 'EGP 0.00'],
    [12_345, 'EGP 123.45'],
    [100_000_000, 'EGP 1,000,000.00'],
    [-4_567, '-EGP 45.67'],
    [7, 'EGP 0.07'],
]);
