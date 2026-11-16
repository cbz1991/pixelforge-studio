<?php

use App\Actions\Generations\ApplyPixelforgeUpdate;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../Generations/Fixtures.php';

beforeEach(fn () => config(['services.pixelforge.webhook_secret' => 'whsec_test']));

function webhookEvent(Generation $generation, string $status, string $eventId, array $overrides = []): array
{
    return [
        'id' => $eventId,
        'object' => 'event',
        'type' => 'generation.'.$status,
        'created_at' => '2026-11-04T14:03:29Z',
        'data' => ['object' => pixelforgeObject($generation, $status, $overrides)],
    ];
}

function deliverWebhook(array $event, ?string $signature = null, int $attempt = 1): TestResponse
{
    $body = json_encode($event);
    $timestamp = time();
    $signature ??= "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", 'whsec_test');

    return test()->call('POST', route('webhooks.pixelforge'), server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => $signature,
        'HTTP_PIXELFORGE_EVENT_ID' => $event['id'] ?? '',
        'HTTP_PIXELFORGE_DELIVERY_ATTEMPT' => (string) $attempt,
    ], content: $body);
}

it('records the cost when a signed success arrives', function () {
    $generation = Generation::factory()->queued()->create();

    deliverWebhook(webhookEvent($generation, 'succeeded', 'evt_1'))->assertNoContent();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->price)->toBe(381_887_500);
});

it('rejects requests without a valid signature', function () {
    $generation = Generation::factory()->queued()->create();

    deliverWebhook(webhookEvent($generation, 'succeeded', 'evt_1'), signature: 't=1,v1=forged')->assertStatus(400);

    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued)
        ->and(WebhookEvent::count())->toBe(0);
});

it('acts on an event delivered twice only once', function () {
    $generation = Generation::factory()->queued()->create();
    $event = webhookEvent($generation, 'succeeded', 'evt_dup');

    deliverWebhook($event)->assertNoContent();
    deliverWebhook($event, attempt: 2)->assertNoContent();

    expect(WebhookEvent::count())->toBe(1)
        ->and($generation->refresh()->price)->toBe(381_887_500);
});

it('reaches the right state when events arrive out of order', function () {
    $generation = Generation::factory()->queued()->create();

    deliverWebhook(webhookEvent($generation, 'succeeded', 'evt_2'));
    deliverWebhook(webhookEvent($generation, 'processing', 'evt_1')); // retried, arrives late

    expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->price)->toBe(381_887_500);
});

it('handles a webhook that arrives before our POST returned', function () {
    $generation = Generation::factory()->create();

    deliverWebhook(webhookEvent($generation, 'processing', 'evt_1', ['id' => 'gen_fast']))->assertNoContent();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Processing)
        ->and($generation->pixelforge_id)->toBe('gen_fast');
});

it('accepts ping and unknown events without doing anything', function () {
    deliverWebhook(['id' => 'evt_ping', 'object' => 'event', 'type' => 'ping', 'data' => []])->assertNoContent();

    expect(WebhookEvent::firstWhere('event_id', 'evt_ping')->type)->toBe('ping');
});

it('acknowledges events for generations that are not ours', function () {
    $stranger = Generation::factory()->queued('gen_stranger')->make();

    deliverWebhook(webhookEvent($stranger, 'succeeded', 'evt_x', ['metadata' => []]))->assertNoContent();
});

it('forgets the event when applying it fails, so the retry is processed', function () {
    $generation = Generation::factory()->queued()->create();
    $event = webhookEvent($generation, 'succeeded', 'evt_retry');

    $this->mock(ApplyPixelforgeUpdate::class)->shouldReceive('__invoke')->andThrow(new RuntimeException('DB down'));
    $this->withoutExceptionHandling();

    expect(fn () => deliverWebhook($event))->toThrow(RuntimeException::class);
    expect(WebhookEvent::count())->toBe(0);

    $this->forgetMock(ApplyPixelforgeUpdate::class);
    $this->app->forgetInstance(ApplyPixelforgeUpdate::class);

    deliverWebhook($event, attempt: 2)->assertNoContent();
    expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded);
});

it('rejects malformed events', function () {
    deliverWebhook(['type' => 'generation.succeeded'])->assertStatus(400);
});
