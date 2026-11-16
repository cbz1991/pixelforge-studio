<?php

use App\Models\Generation;

/**
 * A Pixelforge generation object for one of our generations.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pixelforgeObject(Generation $generation, string $status, array $overrides = []): array
{
    $object = [
        'id' => $generation->pixelforge_id ?? 'gen_'.substr(md5($generation->uuid), 0, 12),
        'object' => 'generation',
        'status' => $status,
        'prompt' => $generation->prompt,
        'size' => $generation->size->value,
        'quality' => $generation->quality->value,
        'reference_images' => $generation->reference_images,
        'metadata' => ['generation_uuid' => $generation->uuid],
        'image_url' => null,
        'image_url_expires_at' => null,
        'usage' => null,
        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'error' => null,
        'created_at' => '2026-11-04T14:03:11Z',
        'started_at' => in_array($status, ['processing', 'succeeded', 'failed']) ? '2026-11-04T14:03:13Z' : null,
        'completed_at' => in_array($status, ['succeeded', 'failed']) ? '2026-11-04T14:03:29Z' : null,
    ];

    if ($status === 'succeeded') {
        $object['image_url'] = 'https://pixelforge.promptedit.dev/images/x.png?signature=abc';
        $object['image_url_expires_at'] = '2026-11-05T14:03:29Z';
        $object['usage'] = [
            'input_text_tokens' => 12,
            'input_image_tokens' => 765,
            'output_tokens' => 6450,
            'reasoning_tokens' => 3184,
            'total_tokens' => 10411,
        ];
    }

    if ($status === 'failed') {
        $object['error'] = ['code' => 'generation_failed', 'message' => 'The model could not produce an image.'];
    }

    return array_replace($object, $overrides);
}
