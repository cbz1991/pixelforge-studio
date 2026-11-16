<?php

namespace App\Pixelforge;

use InvalidArgumentException;

/**
 * The response of GET /v1/pricing: current rates plus the rules for how many
 * tokens a generation uses. Used for estimates only; the actual charge always
 * uses the rates stored on the generation itself.
 */
final readonly class PricingTable
{
    /**
     * @param  array<string, array<string, int>>  $outputTokens  quality => size => tokens
     * @param  numeric-string  $textTokenVariance  e.g. "0.15" for 15%
     * @param  array<string, array{min: int, max: int}>  $reasoningTokens  quality => range
     */
    public function __construct(
        public Rates $rates,
        public int $charactersPerTextToken,
        public string $textTokenVariance,
        public int $tokensPerReferenceImage,
        private array $outputTokens,
        private array $reasoningTokens,
    ) {}

    /** @param array<string, mixed> $response */
    public static function fromArray(array $response): self
    {
        $tokens = $response['tokens'] ?? throw new InvalidArgumentException('Pricing response has no tokens.');

        $variance = (string) $tokens['text_token_variance'];

        if (! is_numeric($variance)) {
            throw new InvalidArgumentException("Invalid text token variance: {$variance}");
        }

        return new self(
            Rates::fromArray($response['rates'] ?? []),
            (int) $tokens['characters_per_text_token'],
            $variance,
            (int) $tokens['per_reference_image'],
            $tokens['output'],
            $tokens['reasoning'],
        );
    }

    public function outputTokens(Quality $quality, Size $size): int
    {
        return (int) ($this->outputTokens[$quality->value][$size->value]
            ?? throw new InvalidArgumentException("No output tokens for {$quality->value} {$size->value}."));
    }

    public function minReasoningTokens(Quality $quality): int
    {
        return (int) $this->reasoningTokens[$quality->value]['min'];
    }

    public function maxReasoningTokens(Quality $quality): int
    {
        return (int) $this->reasoningTokens[$quality->value]['max'];
    }

    /** @return array<string, mixed> same shape as the API response */
    public function toArray(): array
    {
        return [
            'rates' => $this->rates->toArray(),
            'tokens' => [
                'characters_per_text_token' => $this->charactersPerTextToken,
                'text_token_variance' => $this->textTokenVariance,
                'per_reference_image' => $this->tokensPerReferenceImage,
                'output' => $this->outputTokens,
                'reasoning' => $this->reasoningTokens,
            ],
        ];
    }
}
