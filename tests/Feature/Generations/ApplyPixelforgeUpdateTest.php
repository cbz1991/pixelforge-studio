<?php

use App\Actions\Generations\ApplyPixelforgeUpdate;
use App\Enums\GenerationStatus;
use App\Models\Generation;

require_once __DIR__.'/Fixtures.php';

$apply = fn (array $object) => app(ApplyPixelforgeUpdate::class)($object);

it('records the exact cost when a generation succeeds', function () use ($apply) {
    $generation = Generation::factory()->queued()->create();

    $apply(pixelforgeObject($generation, 'succeeded'));

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->provider_cost)->toBe(305_510_000)  // $0.30551
        ->and($generation->price)->toBe(381_887_500)          // +25%
        ->and($generation->usage['reasoning_tokens'])->toBe(3184)
        ->and($generation->image_url)->toContain('signature=abc')
        ->and($generation->completed_at->toIso8601ZuluString())->toBe('2026-11-04T14:03:29Z');
});

it('bills at the rates fixed on the generation, not today\'s', function () use ($apply) {
    $generation = Generation::factory()->queued()->create();

    $apply(pixelforgeObject($generation, 'succeeded', ['rates' => [
        'input_text_tokens' => '10.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ]]));

    expect($generation->refresh()->provider_cost)->toBe(305_510_000 + 12 * 5_000);
});

it('charges nothing for failed generations', function () use ($apply) {
    $generation = Generation::factory()->queued()->create();

    $apply(pixelforgeObject($generation, 'failed'));

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->price)->toBe(0)
        ->and($generation->provider_cost)->toBe(0)
        ->and($generation->error_code)->toBe('generation_failed');
});

it('never moves back from a final status', function () use ($apply) {
    $generation = Generation::factory()->queued()->create();

    $apply(pixelforgeObject($generation, 'succeeded'));
    $apply(pixelforgeObject($generation, 'processing')); // a late, retried webhook
    $apply(pixelforgeObject($generation, 'failed'));

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->price)->toBe(381_887_500);
});

it('records the cost only once when the same success arrives twice', function () use ($apply) {
    $generation = Generation::factory()->queued()->create();

    $apply(pixelforgeObject($generation, 'succeeded'));
    $apply(pixelforgeObject($generation, 'succeeded', [
        'usage' => ['input_text_tokens' => 999, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0],
        'image_url' => 'https://pixelforge.promptedit.dev/images/x.png?signature=fresh',
    ]));

    $generation->refresh();
    expect($generation->provider_cost)->toBe(305_510_000)
        ->and($generation->image_url)->toContain('signature=fresh');
});

it('matches a webhook that arrives before the POST response', function () use ($apply) {
    $generation = Generation::factory()->create(); // still pending, no Pixelforge id yet

    $apply(pixelforgeObject($generation, 'processing', ['id' => 'gen_early']));

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Processing)
        ->and($generation->pixelforge_id)->toBe('gen_early');
});

it('follows Pixelforge when it accepted a generation we had given up on', function () use ($apply) {
    $generation = Generation::factory()->create([
        'status' => GenerationStatus::Failed,
        'error_code' => 'submission_failed',
        'price' => 0,
        'provider_cost' => 0,
    ]);

    $apply(pixelforgeObject($generation, 'succeeded', ['id' => 'gen_late']));

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->price)->toBe(381_887_500)
        ->and($generation->error_code)->toBeNull();
});

it('ignores generations that are not ours', function () use ($apply) {
    $generation = Generation::factory()->queued()->make();

    expect($apply(pixelforgeObject($generation, 'succeeded', ['id' => 'gen_other', 'metadata' => []])))->toBeNull();
});
