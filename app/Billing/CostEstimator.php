<?php

namespace App\Billing;

use App\Pixelforge\PricingTable;
use App\Pixelforge\Quality;
use App\Pixelforge\Size;
use App\Pixelforge\TokenUsage;

final class CostEstimator
{
    public function __construct(private readonly CostCalculator $calculator) {}

    public function estimate(
        PricingTable $pricing,
        string $prompt,
        Size $size,
        Quality $quality,
        int $referenceImages,
    ): CostEstimate {
        $textTokens = $this->estimatedTextTokens($pricing, $prompt);
        $imageTokens = $referenceImages * $pricing->tokensPerReferenceImage;
        $outputTokens = $pricing->outputTokens($quality, $size);

        $min = $pricing->minReasoningTokens($quality);
        $max = $pricing->maxReasoningTokens($quality);

        $expected = new TokenUsage($textTokens, $imageTokens, $outputTokens, intdiv($min + $max, 2));
        $maximum = new TokenUsage($this->maximumTextTokens($pricing, $textTokens), $imageTokens, $outputTokens, $max);

        $expectedCost = $this->calculator->providerCost($expected, $pricing->rates);
        $maximumCost = $this->calculator->providerCost($maximum, $pricing->rates);

        return new CostEstimate(
            $expected,
            $maximum,
            $expectedCost,
            $maximumCost,
            $this->calculator->userPrice($expectedCost),
            $this->calculator->userPrice($maximumCost),
        );
    }

    /** ceil(characters / 4), counting Unicode characters rather than bytes. */
    private function estimatedTextTokens(PricingTable $pricing, string $prompt): int
    {
        $characters = mb_strlen($prompt, 'UTF-8');

        return intdiv($characters + $pricing->charactersPerTextToken - 1, $pricing->charactersPerTextToken);
    }

    /** The real count can be up to 15% above the estimate; round that up. */
    private function maximumTextTokens(PricingTable $pricing, int $estimated): int
    {
        $limit = bcmul((string) $estimated, bcadd('1', $pricing->textTokenVariance, 6), 6);
        $whole = bcadd($limit, '0', 0);

        return (int) (bccomp($limit, $whole, 6) === 1 ? bcadd($whole, '1', 0) : $whole);
    }
}
