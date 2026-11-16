<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/Fixtures.php';

beforeEach(fn () => config(['services.pixelforge.api_key' => 'pf_test', 'services.pixelforge.base_url' => 'https://pixelforge.test/v1']));

it('polls generations whose webhooks went missing', function () {
    $stale = Generation::factory()->queued('gen_stale')->create(['updated_at' => now()->subMinutes(10)]);
    $recent = Generation::factory()->queued('gen_recent')->create();

    Http::fake(['*/generations/gen_stale' => Http::response(pixelforgeObject($stale, 'succeeded'))]);

    $this->artisan('generations:sync')->assertSuccessful();

    expect($stale->refresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($stale->price)->toBe(381_887_500)
        ->and($recent->refresh()->status)->toBe(GenerationStatus::Queued);
    Http::assertSentCount(1);
});

it('stops polling when rate limited', function () {
    Generation::factory()->queued('gen_a')->create(['updated_at' => now()->subMinutes(10)]);
    Generation::factory()->queued('gen_b')->create(['updated_at' => now()->subMinutes(9)]);

    Http::fake(['*' => Http::response(['error' => ['code' => 'rate_limit_exceeded', 'message' => 'Slow down']], 429)]);

    $this->artisan('generations:sync')->assertSuccessful();

    Http::assertSentCount(1);
});
