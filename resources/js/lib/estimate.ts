import type { Pricing, Quality, Size, TokenUsage } from '@/types';

/**
 * Up-front cost estimate, in integer nanodollars, including our markup.
 *
 * Mirrors app/Billing/CostEstimator.php so the price shown while typing is
 * the one the server records. Integer maths only: every intermediate value
 * stays far below Number.MAX_SAFE_INTEGER.
 */
export type Estimate = {
    expected: number;
    maximum: number;
};

/**
 * Counts Unicode code points, like PHP's mb_strlen and Pixelforge's token
 * estimate. String.length would count UTF-16 units (an emoji as 2), and
 * Intl.Segmenter would count graphemes (a family emoji as 1); neither matches.
 */
export function countCharacters(text: string): number {
    // oxlint-disable-next-line typescript/no-misused-spread -- code points are exactly what we want here
    return [...text].length;
}

/** "12.50" dollars per 1M tokens -> 12_500_000_000 nanodollars per 1M tokens. */
function nanodollarsPerMillion(rate: string): number {
    const [whole, fraction = ''] = rate.split('.');

    return Number(whole) * 1e9 + Number(fraction.padEnd(9, '0').slice(0, 9));
}

function providerCost(usage: TokenUsage, pricing: Pricing): number {
    let total = 0;

    for (const type of Object.keys(usage) as (keyof TokenUsage)[]) {
        total += usage[type] * nanodollarsPerMillion(pricing.rates[type]);
    }

    // Divide by 1M tokens once at the end, rounding half up like the server.
    return Math.floor((total + 500_000) / 1_000_000);
}

function withMarkup(cost: number, pricing: Pricing): number {
    return Math.floor((cost * (100 + pricing.markup_percent) + 99) / 100);
}

export function estimate(
    pricing: Pricing,
    prompt: string,
    size: Size,
    quality: Quality,
    referenceImages: number,
): Estimate {
    const { tokens } = pricing;

    const characters = countCharacters(prompt.trim());
    const textTokens = Math.ceil(characters / tokens.characters_per_text_token);
    const varianceBasisPoints = Math.round(
        Number(tokens.text_token_variance) * 10_000,
    );
    const maxTextTokens = Math.ceil(
        (textTokens * (10_000 + varianceBasisPoints)) / 10_000,
    );

    const fixed = {
        input_image_tokens: referenceImages * tokens.per_reference_image,
        output_tokens: tokens.output[quality][size],
    };
    const { min, max } = tokens.reasoning[quality];

    const expected = providerCost(
        {
            ...fixed,
            input_text_tokens: textTokens,
            reasoning_tokens: Math.floor((min + max) / 2),
        },
        pricing,
    );
    const maximum = providerCost(
        { ...fixed, input_text_tokens: maxTextTokens, reasoning_tokens: max },
        pricing,
    );

    return {
        expected: withMarkup(expected, pricing),
        maximum: withMarkup(maximum, pricing),
    };
}
