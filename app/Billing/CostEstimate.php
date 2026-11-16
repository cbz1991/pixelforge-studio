<?php

namespace App\Billing;

use App\Pixelforge\TokenUsage;

/**
 * Up-front estimate for a generation. "Expected" assumes the typical number of
 * text and reasoning tokens; "maximum" assumes the most Pixelforge can bill.
 * All amounts are in nanodollars.
 */
final readonly class CostEstimate
{
    public function __construct(
        public TokenUsage $expectedUsage,
        public TokenUsage $maximumUsage,
        public int $expectedProviderCost,
        public int $maximumProviderCost,
        public int $expectedUserPrice,
        public int $maximumUserPrice,
    ) {}
}
