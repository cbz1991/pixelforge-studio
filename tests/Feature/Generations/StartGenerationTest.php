<?php

use App\Actions\Generations\StartGeneration;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\User;
use App\Pixelforge\Quality;
use App\Pixelforge\Size;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Unit/Billing/PricingFixture.php';

it('saves the generation with its estimate and queues the submission', function () {
    Cache::flush();
    Queue::fake();
    Http::fake(['*/pricing' => Http::response(pricingTable()->toArray())]);

    $generation = app(StartGeneration::class)(
        User::factory()->create(),
        str_repeat('a', 400),
        Size::Landscape,
        Quality::High,
        ['https://example.com/1.png', 'https://example.com/2.png'],
    );

    expect($generation->status)->toBe(GenerationStatus::Pending)
        ->and($generation->maximum_price)->toBe(467_343_750) // docs' worked example + 25%
        ->and($generation->estimated_price)->toBeLessThan($generation->maximum_price);

    Queue::assertPushed(SubmitGeneration::class, fn ($job) => $job->generation->is($generation));
});
