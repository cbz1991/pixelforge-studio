<?php

namespace App\Http\Resources;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Date;

/**
 * Money is sent as integer nanodollars and formatted in the browser, so the
 * numbers shown are exactly the numbers stored.
 *
 * @mixin Generation
 */
class GenerationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $imageExpired = $this->image_url_expires_at !== null && $this->image_url_expires_at->isBefore(Date::now());

        return [
            'id' => $this->uuid,
            'status' => $this->status->value,
            'prompt' => $this->prompt,
            'size' => $this->size->value,
            'quality' => $this->quality->value,
            'reference_images' => $this->reference_images,
            'estimated_price' => $this->estimated_price,
            'maximum_price' => $this->maximum_price,
            'price' => $this->price,
            'usage' => $this->usage,
            'image_url' => $imageExpired ? null : $this->image_url,
            'image_expired' => $imageExpired,
            // While pending, an error is the reason we're retrying, not a failure.
            'notice' => $this->status === GenerationStatus::Pending ? $this->error_message : null,
            'error' => $this->status === GenerationStatus::Failed ? $this->error_message : null,
            'created_at' => $this->created_at->toIso8601ZuluString(),
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),
        ];
    }
}
