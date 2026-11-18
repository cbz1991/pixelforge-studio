<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/Fixtures.php';

beforeEach(function () {
    config(['services.pixelforge.api_key' => 'pf_test', 'services.pixelforge.base_url' => 'https://pixelforge.test/v1']);
    Cache::flush();
});

function runSubmission(Generation $generation): void
{
    app()->call([new SubmitGeneration($generation), 'handle']);
}

function pixelforgeError(string $code, int $status, ?int $retryAfter = null): array
{
    return [['error' => ['code' => $code, 'message' => 'Pixelforge says no.']], $status, $retryAfter ? ['Retry-After' => (string) $retryAfter] : []];
}

it('submits with our uuid as idempotency key and in the metadata', function () {
    $generation = Generation::factory()->create(['reference_images' => ['https://example.com/a.png']]);
    Http::fake(['*/generations' => Http::response(pixelforgeObject($generation, 'queued', ['id' => 'gen_123']), 202)]);

    runSubmission($generation);

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->pixelforge_id)->toBe('gen_123')
        ->and($generation->rates['output_tokens'])->toBe('40.00');

    Http::assertSent(fn (Request $request) => $request->hasHeader('Idempotency-Key', $generation->uuid)
        && $request['metadata'] === ['generation_uuid' => $generation->uuid]
        && $request['reference_images'] === ['https://example.com/a.png']
        && $request['size'] === '1024x1024');
});

it('does not send reference_images when there are none', function () {
    $generation = Generation::factory()->create();
    Http::fake(['*/generations' => Http::response(pixelforgeObject($generation, 'queued'), 202)]);

    runSubmission($generation);

    Http::assertSent(fn (Request $request) => ! isset($request['reference_images']));
});

it('does nothing if the generation was already accepted', function () {
    Http::fake();

    runSubmission(Generation::factory()->queued()->create());

    Http::assertNothingSent();
});

it('fails right away, for free, on errors that retrying cannot fix', function (string $code, int $status) {
    $generation = Generation::factory()->create();
    Http::fake(['*' => Http::response(...pixelforgeError($code, $status))]);

    runSubmission($generation);

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe($code)
        ->and($generation->price)->toBe(0);
})->with([
    ['content_policy_violation', 400],
    ['daily_limit_exceeded', 429],
    ['validation_failed', 422],
]);

it('keeps the generation pending and explains why while retrying', function () {
    $generation = Generation::factory()->create();
    Http::fake(['*' => Http::response(...pixelforgeError('overloaded', 503, 2))]);

    runSubmission($generation);

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Pending)
        ->and($generation->submit_attempts)->toBe(1)
        ->and($generation->error_message)->toContain('overloaded');
});

it('gives up after a few transient failures', function () {
    $generation = Generation::factory()->create(['submit_attempts' => SubmitGeneration::MAX_ATTEMPTS - 1]);
    Http::fake(['*' => Http::response(...pixelforgeError('overloaded', 503, 2))]);

    runSubmission($generation);

    expect($generation->refresh()->status)->toBe(GenerationStatus::Failed)
        ->and($generation->price)->toBe(0)
        ->and($generation->error_message)->toBe('Pixelforge was overloaded on all 5 attempts, so we stopped retrying. You weren\'t charged; please try again later.');
});

it('waits for a concurrency slot without using up attempts', function () {
    $generation = Generation::factory()->create(['submit_attempts' => SubmitGeneration::MAX_ATTEMPTS - 1]);
    Http::fake(['*' => Http::response(...pixelforgeError('concurrency_limit_exceeded', 429, 5))]);

    runSubmission($generation);

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Pending)
        ->and($generation->submit_attempts)->toBe(SubmitGeneration::MAX_ATTEMPTS - 1)
        ->and($generation->error_code)->toBe('concurrency_limit_exceeded');
});

it('pauses all submissions when rate limited', function () {
    $first = Generation::factory()->create();
    $second = Generation::factory()->create();
    Http::fake(['*' => Http::response(...pixelforgeError('rate_limit_exceeded', 429, 30))]);

    runSubmission($first);
    runSubmission($second);

    Http::assertSentCount(1);
});

it('marks the generation failed when the job finally gives up', function () {
    $generation = Generation::factory()->create();

    (new SubmitGeneration($generation))->failed(new RuntimeException('Timed out'));

    expect($generation->refresh()->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe('submission_failed');
});

it('does not fail a generation that was accepted meanwhile', function () {
    $generation = Generation::factory()->queued()->create();

    (new SubmitGeneration($generation))->failed(new RuntimeException('Timed out'));

    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued);
});
