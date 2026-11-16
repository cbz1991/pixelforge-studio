<?php

use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

$client = fn () => new PixelforgeClient('https://pixelforge.test/v1', 'pf_test_key');

it('creates a generation with the API key and idempotency key', function () use ($client) {
    Http::fake(['pixelforge.test/v1/generations' => Http::response(['id' => 'gen_1', 'status' => 'queued'], 202)]);

    $generation = $client()->createGeneration(['prompt' => 'A cat'], 'key-123');

    expect($generation)->toMatchArray(['id' => 'gen_1', 'status' => 'queued']);
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer pf_test_key')
        && $request->hasHeader('Idempotency-Key', 'key-123')
        && $request['prompt'] === 'A cat');
});

it('turns error responses into exceptions with the Pixelforge code', function (int $status, string $code, ?string $retryAfter, bool $retryable) use ($client) {
    Http::fake(['*' => Http::response(
        ['error' => ['type' => 'x', 'code' => $code, 'message' => 'Nope.']],
        $status,
        $retryAfter ? ['Retry-After' => $retryAfter] : [],
    )]);

    try {
        $client()->createGeneration(['prompt' => 'A cat'], 'key');
        $this->fail('Expected an exception.');
    } catch (PixelforgeException $e) {
        expect($e->errorCode)->toBe($code)
            ->and($e->status)->toBe($status)
            ->and($e->retryAfter)->toBe($retryAfter ? (int) $retryAfter : null)
            ->and($e->isRetryable())->toBe($retryable)
            ->and($e->getMessage())->toBe('Nope.');
    }
})->with([
    'rate limit' => [429, 'rate_limit_exceeded', '12', true],
    'concurrency limit' => [429, 'concurrency_limit_exceeded', '5', true],
    'overloaded' => [503, 'overloaded', '2', true],
    'daily limit' => [429, 'daily_limit_exceeded', null, false],
    'content policy' => [400, 'content_policy_violation', null, false],
    'validation' => [422, 'validation_failed', null, false],
    'idempotency key reused' => [409, 'idempotency_key_reused', null, false],
]);

it('keeps the invalid parameter of validation errors', function () use ($client) {
    Http::fake(['*' => Http::response(['error' => [
        'code' => 'validation_failed',
        'message' => 'The selected size is invalid.',
        'param' => 'size',
        'errors' => ['size' => ['The size must be one of: 1024x1024, 1536x1024, 1024x1536.']],
    ]], 422)]);

    expect(fn () => $client()->createGeneration(['prompt' => 'x', 'size' => '1x1'], 'key'))
        ->toThrow(fn (PixelforgeException $e) => expect($e->param)->toBe('size')
            ->and($e->errors)->toHaveKey('size'));
});

it('treats an unexpected server error without a body as retryable', function () use ($client) {
    Http::fake(['*' => Http::response('Bad gateway', 502)]);

    expect(fn () => $client()->generation('gen_1'))
        ->toThrow(fn (PixelforgeException $e) => expect($e->errorCode)->toBe('server_error')
            ->and($e->isRetryable())->toBeTrue());
});

it('treats connection failures as retryable', function () use ($client) {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    expect(fn () => $client()->generation('gen_1'))
        ->toThrow(fn (PixelforgeException $e) => expect($e->errorCode)->toBe('connection_failed')
            ->and($e->isRetryable())->toBeTrue());
});

it('refuses to call authenticated endpoints without an API key', function () {
    Http::fake();

    expect(fn () => (new PixelforgeClient('https://pixelforge.test/v1', null))->generation('gen_1'))
        ->toThrow(PixelforgeException::class, 'PIXELFORGE_API_KEY is not configured.');
    Http::assertNothingSent();
});
