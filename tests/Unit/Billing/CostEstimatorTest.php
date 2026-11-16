<?php

use App\Billing\CostCalculator;
use App\Billing\CostEstimator;
use App\Pixelforge\Quality;
use App\Pixelforge\Size;

require_once __DIR__.'/PricingFixture.php';

$estimator = fn () => new CostEstimator(new CostCalculator(25));

it('matches the worked "most a generation can cost" example from the docs', function () use ($estimator) {
    $estimate = $estimator()->estimate(pricingTable(), str_repeat('a', 400), Size::Landscape, Quality::High, 2);

    expect($estimate->maximumUsage->inputText)->toBe(115)
        ->and($estimate->maximumUsage->inputImage)->toBe(1530)
        ->and($estimate->maximumUsage->output)->toBe(6450)
        ->and($estimate->maximumUsage->reasoning)->toBe(8000)
        ->and($estimate->maximumProviderCost)->toBe(373_875_000)
        ->and($estimate->maximumUserPrice)->toBe(467_343_750);
});

it('expects the estimated text tokens and the middle of the reasoning range', function () use ($estimator) {
    $estimate = $estimator()->estimate(pricingTable(), str_repeat('a', 10), Size::Square, Quality::Medium, 0);

    expect($estimate->expectedUsage->inputText)->toBe(3) // ceil(10 / 4)
        ->and($estimate->expectedUsage->output)->toBe(1100)
        ->and($estimate->expectedUsage->reasoning)->toBe(1100) // (200 + 2000) / 2
        ->and($estimate->expectedProviderCost)->toBe(3 * 5_000 + 1100 * 40_000 + 1100 * 12_500);
});

it('rounds the maximum text tokens up', function () use ($estimator) {
    // ceil(10 / 4) = 3 tokens; 3 * 1.15 = 3.45, so up to 4.
    $estimate = $estimator()->estimate(pricingTable(), str_repeat('a', 10), Size::Square, Quality::Low, 0);

    expect($estimate->maximumUsage->inputText)->toBe(4);
});

it('counts unicode characters, not bytes', function () use ($estimator) {
    $estimate = $estimator()->estimate(pricingTable(), 'ăîșțâ🙂🙂🙂', Size::Square, Quality::Low, 0);

    expect($estimate->expectedUsage->inputText)->toBe(2); // 8 characters
});

it('never expects more than the maximum', function () use ($estimator) {
    foreach (Quality::cases() as $quality) {
        foreach (Size::cases() as $size) {
            $estimate = $estimator()->estimate(pricingTable(), 'A lighthouse at dawn', $size, $quality, 1);

            expect($estimate->expectedUserPrice)->toBeLessThanOrEqual($estimate->maximumUserPrice);
        }
    }
});
