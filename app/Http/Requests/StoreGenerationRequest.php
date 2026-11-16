<?php

namespace App\Http\Requests;

use App\Pixelforge\Quality;
use App\Pixelforge\Size;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mirrors Pixelforge's own limits so users get errors here, instantly, instead
 * of a failed generation later.
 */
class StoreGenerationRequest extends FormRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', Rule::enum(Size::class)],
            'quality' => ['required', Rule::enum(Quality::class)],
            'reference_images' => ['array', 'max:4'],
            'reference_images.*' => ['required', 'url:http,https', 'max:2048', 'distinct'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reference_images.max' => 'You can add at most 4 reference images.',
            'reference_images.*.url' => 'Each reference image must be an http(s) URL.',
            'reference_images.*.distinct' => 'This reference image is listed twice.',
            'reference_images.*.required' => 'Enter a URL or remove this field.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'prompt' => trim((string) $this->input('prompt')),
            'reference_images' => array_values(array_filter(
                array_map(fn ($url) => trim((string) $url), (array) $this->input('reference_images', [])),
                fn (string $url) => $url !== '',
            )),
        ]);
    }

    public function size(): Size
    {
        return Size::from($this->string('size')->value());
    }

    public function quality(): Quality
    {
        return Quality::from($this->string('quality')->value());
    }

    /** @return list<string> */
    public function referenceImages(): array
    {
        return array_values(array_map('strval', (array) $this->validated('reference_images', [])));
    }
}
