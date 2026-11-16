export type GenerationStatus =
    | 'pending'
    | 'queued'
    | 'processing'
    | 'succeeded'
    | 'failed';

export type Size = '1024x1024' | '1536x1024' | '1024x1536';
export type Quality = 'low' | 'medium' | 'high';

export type TokenUsage = {
    input_text_tokens: number;
    input_image_tokens: number;
    output_tokens: number;
    reasoning_tokens: number;
};

/** All money fields are integer nanodollars (1 USD = 1,000,000,000). */
export type Generation = {
    id: string;
    status: GenerationStatus;
    prompt: string;
    size: Size;
    quality: Quality;
    reference_images: string[];
    estimated_price: number;
    maximum_price: number;
    price: number | null;
    usage: TokenUsage | null;
    image_url: string | null;
    image_expired: boolean;
    notice: string | null;
    error: string | null;
    created_at: string;
    completed_at: string | null;
};

/** GET /v1/pricing from Pixelforge, plus our markup. */
export type Pricing = {
    rates: Record<keyof TokenUsage, string>;
    tokens: {
        characters_per_text_token: number;
        text_token_variance: string | number;
        per_reference_image: number;
        output: Record<Quality, Record<Size, number>>;
        reasoning: Record<Quality, { min: number; max: number }>;
    };
    markup_percent: number;
};

export type Paginated<T> = {
    data: T[];
    links: { prev: string | null; next: string | null };
    meta: { current_page: number; last_page: number; total: number };
};
