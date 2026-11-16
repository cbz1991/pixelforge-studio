<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\Quality;
use App\Pixelforge\Size;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'status' => GenerationStatus::Pending,
            'prompt' => fake()->sentence(),
            'size' => Size::Square,
            'quality' => Quality::Medium,
            'reference_images' => [],
            'estimated_price' => 85_000_000,
            'maximum_price' => 132_000_000,
        ];
    }

    public function queued(string $pixelforgeId = 'gen_test'): static
    {
        return $this->state(fn () => [
            'status' => GenerationStatus::Queued,
            'pixelforge_id' => $pixelforgeId,
            'submitted_at' => now(),
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
        ]);
    }
}
