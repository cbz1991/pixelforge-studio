<?php

use App\Billing\CostCalculator;
use App\Billing\Nanodollars;
use App\Pixelforge\Rates;
use App\Pixelforge\TokenUsage;

require_once __DIR__.'/PricingFixture.php';

$rates = fn () => pricingTable()->rates;

it('matches the succeeded generation example from the docs', function () use ($rates) {
    $usage = TokenUsage::fromArray([
        'input_text_tokens' => 12,
        'input_image_tokens' => 765,
        'output_tokens' => 6450,
        'reasoning_tokens' => 3184,
    ]);

    // 12*5 + 765*10 + 6450*40 + 3184*12.5 = 305,510 microdollars
    expect((new CostCalculator(25))->providerCost($usage, $rates()))->toBe(305_510_000);
});

it('matches the cost_usd of the usage example from the docs', function () use ($rates) {
    $usage = new TokenUsage(inputText: 41, inputImage: 0, output: 3300, reasoning: 2950);

    $cost = (new CostCalculator(25))->providerCost($usage, $rates());

    expect(Nanodollars::toDecimal($cost))->toBe('0.169080000');
});

it('is exact for a single token', function () use ($rates) {
    $calculator = new CostCalculator(25);

    expect($calculator->providerCost(new TokenUsage(1, 0, 0, 0), $rates()))->toBe(5_000)
        ->and($calculator->providerCost(new TokenUsage(0, 0, 0, 1), $rates()))->toBe(12_500);
});

it('charges nothing for no tokens', function () use ($rates) {
    expect((new CostCalculator(25))->providerCost(new TokenUsage(0, 0, 0, 0), $rates()))->toBe(0);
});

it('does not drift over many generations like floats would', function () {
    $rates = Rates::fromArray([
        'input_text_tokens' => '0.10',
        'input_image_tokens' => '0',
        'output_tokens' => '0',
        'reasoning_tokens' => '0',
    ]);
    $calculator = new CostCalculator(25);

    $total = 0;
    for ($i = 0; $i < 10_000; $i++) {
        $total += $calculator->providerCost(new TokenUsage(3, 0, 0, 0), $rates);
    }

    expect($total)->toBe(3_000_000);
});

it('adds a 25% markup', function () {
    expect((new CostCalculator(25))->userPrice(305_510_000))->toBe(381_887_500);
});

it('rounds the markup up to the next nanodollar', function () {
    $calculator = new CostCalculator(25);

    expect($calculator->userPrice(1))->toBe(2)   // 1.25
        ->and($calculator->userPrice(3))->toBe(4) // 3.75
        ->and($calculator->userPrice(4))->toBe(5) // exactly 5
        ->and($calculator->userPrice(0))->toBe(0);
});
