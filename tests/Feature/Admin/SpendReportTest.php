<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Cache::flush();
    config(['services.pixelforge.api_key' => 'pf_test', 'services.pixelforge.base_url' => 'https://pixelforge.test/v1']);
    $this->travelTo(now()->setDate(2026, 11, 16)->setTime(12, 0));
});

function succeeded(User $user, string $completedAt, int $providerCost, int $price): Generation
{
    return Generation::factory()->for($user)->queued()->create([
        'status' => GenerationStatus::Succeeded,
        'provider_cost' => $providerCost,
        'price' => $price,
        'completed_at' => $completedAt,
    ]);
}

function usageResponse(array $days): array
{
    $data = [];
    foreach ($days as $date => [$generations, $cost]) {
        $data[] = ['date' => $date, 'generations' => $generations, 'input_text_tokens' => 0, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0, 'cost_usd' => $cost];
    }
    $total = array_sum(array_map(fn ($d) => (int) bcmul($d[1], '1000000000'), $days));

    return ['object' => 'usage', 'data' => $data, 'total' => ['generations' => 0, 'cost_usd' => bcdiv((string) $total, '1000000000', 9)]];
}

it('is only for admins', function () {
    Http::fake();

    $this->actingAs(User::factory()->create())->get(route('admin.report'))->assertForbidden();
});

it('shows spend, provider cost and profit per day and per user, reconciled with Pixelforge', function () {
    $alice = User::factory()->create(['name' => 'Alice', 'email' => 'alice@example.com']);
    $bob = User::factory()->create(['name' => 'Bob', 'email' => 'bob@example.com']);
    $admin = User::factory()->create(['is_admin' => true]);

    succeeded($alice, '2026-11-15 23:59:59', 305_510_000, 381_887_500);
    succeeded($bob, '2026-11-15 08:00:00', 100_000_000, 125_000_000);
    succeeded($alice, '2026-11-16 00:00:00', 200_000_000, 250_000_000); // next UTC day
    Generation::factory()->for($bob)->queued()->create([
        'status' => GenerationStatus::Failed, 'provider_cost' => 0, 'price' => 0, 'completed_at' => '2026-11-16 09:00:00',
    ]);

    Http::fake(['*/usage*' => Http::response(usageResponse([
        '2026-11-15' => [2, '0.405510000'], // matches
        '2026-11-16' => [1, '0.200000005'], // Pixelforge billed 5 nanodollars more
    ]))]);

    $this->actingAs($admin)
        ->get(route('admin.report', ['from' => '2026-11-15', 'to' => '2026-11-16']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Report')
            ->where('report.days.0.date', '2026-11-16')
            ->where('report.days.0.provider_cost', 200_000_000)
            ->where('report.days.0.difference', -5)
            ->where('report.days.1.date', '2026-11-15')
            ->where('report.days.1.generations', 2)
            ->where('report.days.1.credit_spend', 506_887_500)
            ->where('report.days.1.provider_cost', 405_510_000)
            ->where('report.days.1.profit', 101_377_500)
            ->where('report.days.1.difference', 0)
            ->where('report.users.0.email', 'alice@example.com')
            ->where('report.users.0.credit_spend', 631_887_500)
            ->where('report.users.1.email', 'bob@example.com')
            ->where('report.users.1.generations', 1)
            ->has('report.daysByUser', 3)
            ->where('report.totals.provider_cost', 605_510_000)
            ->where('report.totals.pixelforge_cost', 605_510_005)
            ->where('report.totals.difference', -5));

    Http::assertSent(fn ($request) => str_contains($request->url(), 'start_date=2026-11-15') && str_contains($request->url(), 'end_date=2026-11-16'));
});

it('still shows our own numbers when Pixelforge usage is unavailable', function () {
    Http::fake(['*/usage*' => Http::response(['error' => ['code' => 'overloaded', 'message' => 'Busy']], 503)]);
    succeeded(User::factory()->create(), '2026-11-16 10:00:00', 100_000_000, 125_000_000);

    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->get(route('admin.report'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.totals.credit_spend', 125_000_000)
            ->where('report.totals.pixelforge_cost', null)
            ->where('report.pixelforgeError', 'Busy'));
});

it('caps the range at what Pixelforge accepts', function () {
    Http::fake(['*/usage*' => Http::response(usageResponse([]))]);

    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->get(route('admin.report', ['from' => '2026-01-01', 'to' => '2026-11-16']))
        ->assertInertia(fn (Assert $page) => $page->where('from', '2026-08-17')->has('report.days', 92));
});

it('makes a user an admin from the command line', function () {
    $user = User::factory()->create(['email' => 'ops@example.com']);

    $this->artisan('users:make-admin', ['email' => 'ops@example.com'])->assertSuccessful();
    expect($user->refresh()->is_admin)->toBeTrue();

    $this->artisan('users:make-admin', ['email' => 'ops@example.com', '--revoke' => true])->assertSuccessful();
    expect($user->refresh()->is_admin)->toBeFalse();
});
