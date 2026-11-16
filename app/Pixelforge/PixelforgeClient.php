<?php

namespace App\Pixelforge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around the Pixelforge REST API.
 *
 * It makes exactly one attempt per call and throws a PixelforgeException on any
 * error. Retrying (and how long to wait) is the caller's decision, because a web
 * request and a queued job should react very differently to a 429.
 */
class PixelforgeClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
    ) {}

    /** GET /v1/pricing (no authentication needed). */
    public function pricing(): PricingTable
    {
        return PricingTable::fromArray($this->send(fn () => $this->http()->get('pricing')));
    }

    /**
     * POST /v1/generations. Replaying the same idempotency key with the same
     * parameters returns the existing generation instead of creating a new one.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed> the generation object
     */
    public function createGeneration(array $parameters, string $idempotencyKey): array
    {
        return $this->send(fn () => $this->authenticated()
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->post('generations', $parameters));
    }

    /** @return array<string, mixed> the generation object */
    public function generation(string $id): array
    {
        return $this->send(fn () => $this->authenticated()->get('generations/'.rawurlencode($id)));
    }

    /**
     * GET /v1/usage: what Pixelforge billed this API key per UTC day.
     *
     * @return array<string, mixed>
     */
    public function usage(string $startDate, string $endDate): array
    {
        return $this->send(fn () => $this->authenticated()->get('usage', [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]));
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/').'/')
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(15);
    }

    private function authenticated(): PendingRequest
    {
        if (blank($this->apiKey)) {
            throw new PixelforgeException('invalid_api_key', 'PIXELFORGE_API_KEY is not configured.');
        }

        return $this->http()->withToken($this->apiKey);
    }

    /**
     * @param  callable(): Response  $request
     * @return array<string, mixed>
     */
    private function send(callable $request): array
    {
        try {
            $response = $request();
        } catch (ConnectionException $e) {
            throw PixelforgeException::connectionFailed($e);
        }

        if ($response->successful()) {
            return (array) $response->json();
        }

        throw $this->toException($response);
    }

    private function toException(Response $response): PixelforgeException
    {
        $error = (array) $response->json('error', []);
        $retryAfter = $response->header('Retry-After');

        return new PixelforgeException(
            errorCode: (string) ($error['code'] ?? ($response->serverError() ? 'server_error' : 'unknown_error')),
            message: (string) ($error['message'] ?? "Pixelforge returned HTTP {$response->status()}."),
            status: $response->status(),
            retryAfter: is_numeric($retryAfter) ? (int) $retryAfter : null,
            param: isset($error['param']) ? (string) $error['param'] : null,
            errors: (array) ($error['errors'] ?? []),
        );
    }
}
