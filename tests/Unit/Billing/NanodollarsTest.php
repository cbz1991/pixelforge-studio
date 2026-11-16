<?php

use App\Billing\Nanodollars;

it('converts exact decimals both ways', function () {
    expect(Nanodollars::fromDecimal('0.046125000'))->toBe(46_125_000)
        ->and(Nanodollars::fromDecimal('12'))->toBe(12_000_000_000)
        ->and(Nanodollars::toDecimal(46_125_000))->toBe('0.046125000')
        ->and(Nanodollars::toDecimal(0))->toBe('0.000000000');
});

it('rejects amounts finer than a nanodollar', function () {
    Nanodollars::fromDecimal('0.0000000001');
})->throws(InvalidArgumentException::class);
