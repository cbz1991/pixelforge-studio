<?php

namespace App\Jobs;

use App\Actions\Generations\ApplyPixelforgeUpdate;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * Sends a generation to Pixelforge, retrying while Pixelforge says "not now".
 *
 * Every attempt uses the generation's uuid as the Idempotency-Key, so a retry
 * after a timeout can never create (or bill) a second generation.
 */
class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    /** Transient failures (overloaded, rate limited, network) before giving up. */
    public const MAX_ATTEMPTS = 5;

    /** How long we keep waiting for a free concurrency slot. */
    public const WAIT_FOR_SLOT_MINUTES = 15;

    /** Set when Pixelforge rate limits us, so every submission pauses, not just this one. */
    public const PAUSE_KEY = 'pixelforge:paused-until';

    public int $tries = 0;

    public function __construct(public Generation $generation) {}

    public function retryUntil(): \DateTimeInterface
    {
        return Date::now()->addMinutes(self::WAIT_FOR_SLOT_MINUTES);
    }

    public function handle(PixelforgeClient $client, ApplyPixelforgeUpdate $applyUpdate): void
    {
        $generation = $this->generation->fresh();

        if ($generation?->status !== GenerationStatus::Pending) {
            return; // Already accepted (or a webhook got here first).
        }

        $pausedFor = (int) Cache::get(self::PAUSE_KEY, 0) - Date::now()->getTimestamp();

        if ($pausedFor > 0) {
            $this->release($pausedFor + random_int(0, 3));

            return;
        }

        try {
            $object = $client->createGeneration($this->parameters($generation), $generation->uuid);
        } catch (PixelforgeException $e) {
            $this->handleRejection($generation, $e);

            return;
        }

        $generation->increment('submit_attempts');
        $applyUpdate($object);
    }

    /** Called by the queue when retryUntil() passes or the job crashes. */
    public function failed(?Throwable $exception): void
    {
        $this->giveUp(
            $this->generation,
            'submission_failed',
            'We couldn\'t get Pixelforge to accept this generation. You weren\'t charged.',
        );
    }

    /** @return array<string, mixed> */
    private function parameters(Generation $generation): array
    {
        return array_filter([
            'prompt' => $generation->prompt,
            'size' => $generation->size->value,
            'quality' => $generation->quality->value,
            'reference_images' => $generation->reference_images ?: null,
            'metadata' => ['generation_uuid' => $generation->uuid],
        ], fn ($value) => $value !== null);
    }

    private function handleRejection(Generation $generation, PixelforgeException $e): void
    {
        if (! $e->isRetryable()) {
            $this->giveUp($generation, $e->errorCode, $this->explain($e));

            return;
        }

        // Concurrency rejections just mean "wait for a slot", so they don't count
        // as failed attempts; retryUntil() bounds how long we wait.
        if ($e->errorCode !== 'concurrency_limit_exceeded') {
            $generation->increment('submit_attempts');

            if ($generation->submit_attempts >= self::MAX_ATTEMPTS) {
                $this->giveUp($generation, $e->errorCode, 'Pixelforge kept failing, so we stopped retrying. You weren\'t charged.');

                return;
            }
        }

        if ($e->errorCode === 'rate_limit_exceeded' && $e->retryAfter) {
            Cache::put(self::PAUSE_KEY, Date::now()->getTimestamp() + $e->retryAfter, $e->retryAfter);
        }

        $generation->update(['error_code' => $e->errorCode, 'error_message' => $this->explain($e)]);

        $this->release($this->delay($e, $generation->submit_attempts));
    }

    /** Respect Retry-After, back off exponentially, and add jitter to avoid stampedes. */
    private function delay(PixelforgeException $e, int $attempts): int
    {
        $backoff = min(60, 2 ** max(1, $attempts));

        return max($e->retryAfter ?? 0, $backoff) + random_int(0, 2);
    }

    private function giveUp(Generation $generation, string $code, string $message): void
    {
        Generation::query()
            ->whereKey($generation->getKey())
            ->where('status', GenerationStatus::Pending)
            ->update([
                'status' => GenerationStatus::Failed,
                'error_code' => $code,
                'error_message' => $message,
                'provider_cost' => 0,
                'price' => 0,
                'completed_at' => Date::now(),
                'updated_at' => Date::now(),
            ]);
    }

    /** A message the user can act on. */
    private function explain(PixelforgeException $e): string
    {
        return match ($e->errorCode) {
            'concurrency_limit_exceeded' => 'Waiting for a free slot: Pixelforge runs 3 generations at a time.',
            'rate_limit_exceeded' => 'Pixelforge is rate limiting us. Retrying shortly.',
            'overloaded', 'server_error' => 'Pixelforge is overloaded. Retrying shortly.',
            'connection_failed' => 'Couldn\'t reach Pixelforge. Retrying shortly.',
            'content_policy_violation' => 'Pixelforge rejected this prompt under its content policy. You weren\'t charged.',
            'daily_limit_exceeded' => 'Pixelforge\'s daily generation limit is used up. Try again after midnight UTC. You weren\'t charged.',
            'validation_failed' => 'Pixelforge rejected the request: '.$e->getMessage(),
            default => $e->getMessage(),
        };
    }
}
