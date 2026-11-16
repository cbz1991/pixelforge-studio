<?php

use App\Pixelforge\PixelforgeException;
use App\Pixelforge\PricingRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../../Unit/Billing/PricingFixture.php';

beforeEach(fn () => Cache::flush());

it('caches pricing between calls', function () {
    Http::fake(['*/pricing' => Http::response(pricingTable()->toArray())]);

    app(PricingRepository::class)->current();
    $pricing = app(PricingRepository::class)->current();

    expect($pricing->rates->toArray()['output_tokens'])->toBe('40.00');
    Http::assertSentCount(1);
});

it('falls back to the last known pricing when Pixelforge is down', function () {
    Http::fakeSequence('*/pricing')
        ->push(pricingTable()->toArray())
        ->push(['error' => ['code' => 'overloaded', 'message' => 'Busy']], 503);

    app(PricingRepository::class)->current();
    Cache::forget('pixelforge:pricing'); // the short-lived cache expires

    expect(app(PricingRepository::class)->current()->rates->toArray()['output_tokens'])->toBe('40.00');
});

it('fails when Pixelforge is down and there is no pricing to fall back to', function () {
    Http::fake(['*/pricing' => Http::response(['error' => ['code' => 'overloaded', 'message' => 'Busy']], 503)]);

    app(PricingRepository::class)->current();
})->throws(PixelforgeException::class);
