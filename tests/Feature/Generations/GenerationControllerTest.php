<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\PixelforgeException;
use App\Pixelforge\PricingRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../../Unit/Billing/PricingFixture.php';

beforeEach(function () {
    Cache::flush();
    Queue::fake();
    Http::fake(['*/pricing' => Http::response(pricingTable()->toArray())]);
});

it('sends guests to the login page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

it('shows the user their own generations and the pricing', function () {
    $user = User::factory()->create();
    Generation::factory()->for($user)->create(['prompt' => 'Mine']);
    Generation::factory()->create(['prompt' => 'Someone else\'s']);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('generations/Index')
            ->has('generations.data', 1)
            ->where('generations.data.0.prompt', 'Mine')
            ->where('pricing.markup_percent', 25)
            ->where('pricing.rates.output_tokens', '40.00'));
});

it('starts a generation', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('generations.store'), [
        'prompt' => '  A lighthouse at dawn  ',
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/a.png', ''],
    ])->assertRedirect(route('dashboard'));

    $generation = $user->generations()->sole();
    expect($generation->status)->toBe(GenerationStatus::Pending)
        ->and($generation->prompt)->toBe('A lighthouse at dawn')
        ->and($generation->reference_images)->toBe(['https://example.com/a.png']);
    Queue::assertPushed(SubmitGeneration::class);
});

it('validates the request like Pixelforge would', function (array $input, string $error) {
    $this->actingAs(User::factory()->create())
        ->post(route('generations.store'), [...['prompt' => 'A cat', 'size' => '1024x1024', 'quality' => 'low'], ...$input])
        ->assertSessionHasErrors($error);

    Queue::assertNothingPushed();
})->with([
    'missing prompt' => [['prompt' => '   '], 'prompt'],
    'prompt too long' => [['prompt' => str_repeat('a', 4001)], 'prompt'],
    'unknown size' => [['size' => '512x512'], 'size'],
    'unknown quality' => [['quality' => 'ultra'], 'quality'],
    'too many references' => [['reference_images' => array_map(fn ($i) => "https://example.com/{$i}.png", range(1, 5))], 'reference_images'],
    'not a url' => [['reference_images' => ['ftp://example.com/a.png']], 'reference_images.0'],
]);

it('explains when pricing is unavailable', function () {
    $this->mock(PricingRepository::class)
        ->shouldReceive('current')
        ->andThrow(new PixelforgeException('overloaded', 'Busy', 503));

    $this->actingAs(User::factory()->create())
        ->post(route('generations.store'), ['prompt' => 'A cat', 'size' => '1024x1024', 'quality' => 'low'])
        ->assertSessionHasErrors('prompt');

    Queue::assertNothingPushed();
});

it('hides image links once they have expired', function () {
    $user = User::factory()->create();
    Generation::factory()->for($user)->queued()->create([
        'status' => GenerationStatus::Succeeded,
        'image_url' => 'https://pixelforge.test/x.png',
        'image_url_expires_at' => now()->subMinute(),
    ]);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('generations.data.0.image_url', null)
            ->where('generations.data.0.image_expired', true));
});
