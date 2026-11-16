<?php

namespace App\Actions\Generations;

use App\Billing\CostCalculator;
use App\Billing\CreditLedger;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\Rates;
use App\Pixelforge\TokenUsage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Brings our record in line with a Pixelforge generation object, wherever it
 * came from: the POST response, a webhook, or polling.
 *
 * Safe to call any number of times, in any order: the row is locked, statuses
 * only move forward, and the cost is recorded once, on the move to succeeded.
 */
class ApplyPixelforgeUpdate
{
    public function __construct(
        private readonly CostCalculator $calculator,
        private readonly CreditLedger $ledger,
    ) {}

    /**
     * @param  array<string, mixed>  $object  a Pixelforge generation object
     * @return Generation|null null if the generation isn't one of ours
     */
    public function __invoke(array $object): ?Generation
    {
        $generation = DB::transaction(function () use ($object): ?Generation {
            $generation = $this->findAndLock($object);

            if ($generation === null) {
                return null;
            }

            $this->apply($generation, $object);

            return $generation;
        });

        if ($generation === null) {
            Log::warning('Pixelforge update for an unknown generation.', ['id' => $object['id'] ?? null]);
        }

        return $generation;
    }

    /** @param array<string, mixed> $object */
    private function findAndLock(array $object): ?Generation
    {
        $uuid = $object['metadata']['generation_uuid'] ?? null;

        // Match on our own id first: a webhook can beat the POST response, before
        // we know Pixelforge's id.
        return Generation::query()
            ->when(
                is_string($uuid),
                fn ($query) => $query->where('uuid', $uuid),
                fn ($query) => $query->where('pixelforge_id', (string) ($object['id'] ?? '')),
            )
            ->lockForUpdate()
            ->first();
    }

    /** @param array<string, mixed> $object */
    private function apply(Generation $generation, array $object): void
    {
        $next = GenerationStatus::tryFrom((string) ($object['status'] ?? ''));

        if ($next === null) {
            return;
        }

        $current = $generation->status;

        // We gave up submitting (e.g. timeouts) but Pixelforge had accepted it after
        // all. It will bill us, so follow its lead rather than our local failure.
        if ($current === GenerationStatus::Failed && $generation->pixelforge_id === null) {
            $current = GenerationStatus::Pending;
            $generation->error_code = null;
            $generation->error_message = null;
            $generation->price = null;
            $generation->provider_cost = null;
        }

        $generation->pixelforge_id ??= (string) $object['id'];
        $generation->rates ??= isset($object['rates']) ? Rates::fromArray($object['rates'])->toArray() : null;
        $generation->submitted_at ??= $this->time($object['created_at'] ?? null);

        if ($current->canMoveTo($next)) {
            $generation->status = $next;
            $generation->started_at ??= $this->time($object['started_at'] ?? null);

            match ($next) {
                GenerationStatus::Succeeded => $this->succeed($generation, $object),
                GenerationStatus::Failed => $this->fail($generation, $object),
                default => null,
            };

            if ($next !== GenerationStatus::Failed) {
                // Clear any "retrying because…" note left from submission.
                $generation->error_code = null;
                $generation->error_message = null;
            }
        } elseif ($next === GenerationStatus::Succeeded && $current === GenerationStatus::Succeeded) {
            // Same final state again: only refresh the image link, never the cost.
            $generation->image_url = $object['image_url'] ?? $generation->image_url;
            $generation->image_url_expires_at = $this->time($object['image_url_expires_at'] ?? null) ?? $generation->image_url_expires_at;
        }

        $generation->save();

        // Same transaction as the status change: credits move exactly when it becomes final.
        $this->ledger->settle($generation);
    }

    /** @param array<string, mixed> $object */
    private function succeed(Generation $generation, array $object): void
    {
        // The rates fixed when the generation was created, not today's pricing.
        $rates = Rates::fromArray($object['rates'] ?? $generation->rates ?? []);
        $usage = TokenUsage::fromArray((array) ($object['usage'] ?? []));

        $generation->rates = $rates->toArray();
        $generation->usage = $usage->toArray();
        $generation->provider_cost = $this->calculator->providerCost($usage, $rates);
        $generation->price = $this->calculator->userPrice($generation->provider_cost);
        $generation->image_url = $object['image_url'] ?? null;
        $generation->image_url_expires_at = $this->time($object['image_url_expires_at'] ?? null);
        $generation->completed_at = $this->time($object['completed_at'] ?? null) ?? Date::now();
    }

    /** @param array<string, mixed> $object */
    private function fail(Generation $generation, array $object): void
    {
        // Pixelforge never bills failed generations, and neither do we.
        $generation->provider_cost = 0;
        $generation->price = 0;
        $generation->error_code = (string) ($object['error']['code'] ?? 'generation_failed');
        $generation->error_message = (string) ($object['error']['message'] ?? 'The generation failed.');
        $generation->completed_at = $this->time($object['completed_at'] ?? null) ?? Date::now();
    }

    private function time(mixed $value): ?CarbonImmutable
    {
        return is_string($value) ? CarbonImmutable::parse($value) : null;
    }
}
