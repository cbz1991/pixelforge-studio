<?php

namespace App\Actions\Generations;

use App\Billing\CostEstimator;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\PricingRepository;
use App\Pixelforge\Quality;
use App\Pixelforge\Size;
use Illuminate\Support\Str;

class StartGeneration
{
    public function __construct(
        private readonly PricingRepository $pricing,
        private readonly CostEstimator $estimator,
    ) {}

    /** @param list<string> $referenceImages */
    public function __invoke(User $user, string $prompt, Size $size, Quality $quality, array $referenceImages): Generation
    {
        $estimate = $this->estimator->estimate($this->pricing->current(), $prompt, $size, $quality, count($referenceImages));

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

        SubmitGeneration::dispatch($generation)->afterCommit();

        return $generation;
    }
}
