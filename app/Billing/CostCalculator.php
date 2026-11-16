<?php

namespace App\Billing;

use App\Pixelforge\Rates;
use App\Pixelforge\TokenType;
use App\Pixelforge\TokenUsage;

final class CostCalculator
{
    public function __construct(private readonly int $markupPercent) {}

    /**
     * What Pixelforge charges us, in nanodollars: sum of tokens x rate / 1M.
     *
     * Done in decimal arithmetic and converted once at the end. With rates of up
     * to three decimals (all of Pixelforge's so far) the result is exact; anything
     * finer is rounded half-up to the nanodollar.
     */
    public function providerCost(TokenUsage $usage, Rates $rates): int
    {
        $dollars = '0';

        foreach (TokenType::cases() as $type) {
            $tokenCost = bcdiv(bcmul((string) $usage->of($type), $rates->perMillion($type), 12), '1000000', 18);
            $dollars = bcadd($dollars, $tokenCost, 18);
        }

        $nanodollars = bcmul($dollars, (string) Nanodollars::PER_DOLLAR, 18);

        return (int) bcadd($nanodollars, '0.5', 0);
    }

    /**
     * What we charge the user: provider cost plus markup, rounded up to the
     * next nanodollar so we never undercharge by a fraction.
     */
    public function userPrice(int $providerCost): int
    {
        $numerator = $providerCost * (100 + $this->markupPercent);

        return intdiv($numerator + 99, 100);
    }
}
