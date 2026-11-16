<?php

use App\Actions\Generations\ApplyPixelforgeUpdate;
use App\Billing\CreditLedger;
use App\Billing\InsufficientCredits;
use App\Enums\CreditTransactionType as Type;
use App\Jobs\SubmitGeneration;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;

require_once __DIR__.'/../Generations/Fixtures.php';

const TEN_DOLLARS = 10_000_000_000;

/** A $10 user with a queued generation whose maximum is held. */
function heldGeneration(): Generation
{
    $user = User::factory()->withCredits(10)->create();
    $generation = Generation::factory()->for($user)->queued()->create(['maximum_price' => 500_000_000]);

    app(CreditLedger::class)->hold($generation);

    return $generation;
}

function balanceOf(Generation $generation): int
{
    return $generation->user->refresh()->balance;
}

function ledgerSum(User $user): int
{
    return (int) CreditTransaction::where('user_id', $user->id)->sum('amount');
}

it('holds the maximum price while the generation runs', function () {
    $generation = heldGeneration();

    expect(balanceOf($generation))->toBe(TEN_DOLLARS - 500_000_000);
});

it('refuses to hold more than the balance', function () {
    $user = User::factory()->withCredits(5)->create();
    $generation = Generation::factory()->for($user)->create(['maximum_price' => 6_000_000_000]);

    expect(fn () => app(CreditLedger::class)->hold($generation))
        ->toThrow(fn (InsufficientCredits $e) => expect($e->required)->toBe(6_000_000_000)->and($e->balance)->toBe(5_000_000_000));

    expect($user->refresh()->balance)->toBe(5_000_000_000);
});

it('charges exactly the actual price on success', function () {
    $generation = heldGeneration();

    app(ApplyPixelforgeUpdate::class)(pixelforgeObject($generation, 'succeeded'));

    expect(balanceOf($generation))->toBe(TEN_DOLLARS - 381_887_500)
        ->and(ledgerSum($generation->user))->toBe(balanceOf($generation));
});

it('gives everything back on failure', function () {
    $generation = heldGeneration();

    app(ApplyPixelforgeUpdate::class)(pixelforgeObject($generation, 'failed'));

    expect(balanceOf($generation))->toBe(TEN_DOLLARS);
});

it('gives everything back when submission gives up', function () {
    $user = User::factory()->withCredits(10)->create();
    $generation = Generation::factory()->for($user)->create(['maximum_price' => 500_000_000]);
    app(CreditLedger::class)->hold($generation);

    (new SubmitGeneration($generation))->failed(new RuntimeException('Timed out'));

    expect($user->refresh()->balance)->toBe(TEN_DOLLARS);
});

it('never charges twice, whatever order and number of updates arrive', function () {
    $generation = heldGeneration();
    $apply = app(ApplyPixelforgeUpdate::class);

    $apply(pixelforgeObject($generation, 'processing'));
    $apply(pixelforgeObject($generation, 'succeeded'));
    $apply(pixelforgeObject($generation, 'succeeded'));
    $apply(pixelforgeObject($generation, 'processing'));
    $apply(pixelforgeObject($generation, 'failed'));
    app(CreditLedger::class)->settle($generation->refresh());

    expect(balanceOf($generation))->toBe(TEN_DOLLARS - 381_887_500)
        ->and(CreditTransaction::where('generation_id', $generation->id)->pluck('type')->map->value->sort()->values()->all())
        ->toBe(['charge', 'hold', 'hold_release']);
});

it('charges once when Pixelforge accepted a generation we had given up on', function () {
    $user = User::factory()->withCredits(10)->create();
    $generation = Generation::factory()->for($user)->create(['maximum_price' => 500_000_000]);
    app(CreditLedger::class)->hold($generation);
    (new SubmitGeneration($generation))->failed(new RuntimeException('Timed out')); // hold released

    app(ApplyPixelforgeUpdate::class)(pixelforgeObject($generation->refresh(), 'succeeded', ['id' => 'gen_late']));

    expect($user->refresh()->balance)->toBe(TEN_DOLLARS - 381_887_500)
        ->and(ledgerSum($user))->toBe($user->balance);
});

it('does not release a hold that never existed', function () {
    $user = User::factory()->withCredits(10)->create();
    $generation = Generation::factory()->for($user)->queued()->create(); // no hold, e.g. created before balances

    app(ApplyPixelforgeUpdate::class)(pixelforgeObject($generation, 'failed'));

    expect($user->refresh()->balance)->toBe(TEN_DOLLARS)
        ->and(CreditTransaction::where('type', Type::HoldRelease)->count())->toBe(0);
});
