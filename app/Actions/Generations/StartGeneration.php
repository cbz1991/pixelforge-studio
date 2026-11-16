<?php

namespace App\Actions\Generations;

use App\Billing\CostEstimator;
use App\Billing\CreditLedger;
use App\Billing\InsufficientCredits;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\PricingRepository;
use App\Pixelforge\Quality;
use App\Pixelforge\Size;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StartGeneration
{
    public function __construct(
        private readonly PricingRepository $pricing,
        private readonly CostEstimator $estimator,
        private readonly CreditLedger $ledger,
    ) {}

    /**
     * @param  list<string>  $referenceImages
     *
     * @throws InsufficientCredits
     */
    public function __invoke(User $user, string $prompt, Size $size, Quality $quality, array $referenceImages): Generation
    {
        $estimate = $this->estimator->estimate($this->pricing->current(), $prompt, $size, $quality, count($referenceImages));

        $generation = DB::transaction(function () use ($user, $prompt, $size, $quality, $referenceImages, $estimate): Generation {
            $generation = $user->generations()->create([
                'uuid' => (string) Str::uuid(),
                'status' => GenerationStatus::Pending,
                'prompt' => $prompt,
                'size' => $size,
                'quality' => $quality,
                'reference_images' => $referenceImages,
                'estimated_price' => $estimate->expectedUserPrice,
                'maximum_price' => $estimate->maximumUserPrice,
            ]);

            // Throws (and rolls back the generation) if the user can't cover the worst case.
            $this->ledger->hold($generation);

            return $generation;
        });

        SubmitGeneration::dispatch($generation)->afterCommit();

        return $generation;
    }
}
